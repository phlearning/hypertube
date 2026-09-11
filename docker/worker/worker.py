import json
import os
import threading
import time

import libtorrent as lt
import redis
import requests

REDIS_HOST = os.environ.get("REDIS_HOST", "redis")
REDIS_PORT = int(os.environ.get("REDIS_PORT", "6379"))
JOBS_QUEUE = os.environ.get("TORRENT_JOBS_QUEUE", "torrent:jobs")
CALLBACK_URL = os.environ["LARAVEL_INTERNAL_URL"]
WORKER_SECRET = os.environ["TORRENT_WORKER_SECRET"]

SHARED_DIR = "/shared"
PRIORITY_WINDOW_PIECES = 16
REPORT_THRESHOLD_BYTES = 256 * 1024
POLL_INTERVAL_SECONDS = 1
STALL_TIMEOUT_SECONDS = 120
VIDEO_EXTENSIONS = {".mp4", ".mkv", ".avi", ".webm", ".mov", ".ogv"}


def connect_redis() -> redis.Redis:
    client = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, decode_responses=True)
    while True:
        try:
            client.ping()
            return client
        except redis.exceptions.ConnectionError:
            print(f"waiting for redis at {REDIS_HOST}:{REDIS_PORT}...", flush=True)
            time.sleep(2)


def report(job_id: str, **fields) -> None:
    response = requests.post(
        CALLBACK_URL,
        json={"job_id": job_id, **fields},
        headers={"Authorization": f"Bearer {WORKER_SECRET}"},
        timeout=10,
    )
    response.raise_for_status()


def handle_ping(job_id: str) -> None:
    report(job_id, status="completed", message="pong from python worker")


def select_target_file(info) -> int:
    """
    Pick which file within the torrent is "the movie". A single-file
    torrent has only one candidate. A multi-file torrent — every
    archive.org item torrent bundles every format variant (mp4/mkv/avi/...)
    plus metadata, subtitles and logs in one download — needs one picked
    out: the largest file with a recognised video extension, falling back
    to the largest file overall if none matches.
    """
    files = info.files()
    indices = range(files.num_files())
    video_indices = [i for i in indices if os.path.splitext(files.file_name(i))[1].lower() in VIDEO_EXTENSIONS]
    pool = video_indices or indices

    return max(pool, key=files.file_size)


def contiguous_bytes_done(status, info, file_index: int) -> int:
    """
    How many bytes, counted from the start of the target file with no gap,
    are confirmed complete. This is deliberately NOT status.total_wanted_done:
    that's a sum of all completed pieces regardless of order, and
    sequential_download only biases piece *selection* — under a real
    multi-peer swarm a later piece can still finish before an earlier one,
    leaving a hole. The streaming endpoint trusts this value as "safe to
    read from byte 0 of this file"; reporting total_wanted_done would let
    it serve bytes from beyond a gap as if they were valid downloaded data.

    A multi-file torrent's pieces are numbered across the whole torrent, not
    per file, so this starts scanning from the piece that contains the
    target file's first byte rather than from piece 0.
    """
    files = info.files()
    file_size = files.file_size(file_index)
    file_offset = files.file_offset(file_index)
    piece_length = info.piece_length()
    first_piece = file_offset // piece_length

    pieces = status.pieces
    complete_pieces = 0
    for piece_complete in pieces[first_piece:]:
        if not piece_complete:
            break
        complete_pieces += 1

    if complete_pieces == 0:
        return 0

    contiguous_end = (first_piece + complete_pieces) * piece_length
    available = contiguous_end - file_offset

    return min(available, file_size)


def handle_download(job_id: str, torrent_url: str) -> None:
    try:
        torrent_response = requests.get(torrent_url, timeout=30)
        torrent_response.raise_for_status()
        info = lt.torrent_info(lt.bdecode(torrent_response.content))
        target_index = select_target_file(info)
    except Exception as exc:  # noqa: BLE001 - reported back as a failed job, not crashed worker
        report(job_id, status="failed", message=f"could not prepare torrent: {exc}")
        return

    files = info.files()
    file_size = files.file_size(target_index)
    file_offset = files.file_offset(target_index)
    # file_path() is relative to save_path; for a single-file torrent this is
    # just the file's own name, so this also covers that case unchanged.
    file_path = os.path.join(SHARED_DIR, files.file_path(target_index))

    # Every other file in the torrent (archive.org bundles every format
    # variant plus metadata/subtitles/logs alongside the actual movie) is
    # deselected so libtorrent doesn't spend bandwidth and disk on hundreds
    # of megabytes nobody asked for. A shared piece straddling the target
    # file's boundary is still fetched: libtorrent takes a piece's priority
    # as the max across every file that overlaps it.
    priorities = [0] * files.num_files()
    priorities[target_index] = 4

    # Port 0 lets the OS assign an ephemeral port per session, since multiple
    # downloads now run concurrently in this same process (each needs its own
    # libtorrent session, and a fixed port would collide across them).
    session = lt.session(
        {
            "listen_interfaces": "0.0.0.0:0",
            "enable_dht": False,
            "enable_lsd": False,
            "enable_natpmp": False,
            "enable_upnp": False,
        }
    )
    handle = session.add_torrent(
        {
            "ti": info,
            "save_path": SHARED_DIR,
            "flags": lt.torrent_flags.sequential_download,
        }
    )
    handle.prioritize_files(priorities)

    # Front-load deadlines on the target file's first pieces so libtorrent
    # fetches its start with priority, on top of the sequential ordering
    # above. Piece 0 of the torrent is not piece 0 of this file in a
    # multi-file torrent — the file can start partway into the piece space.
    first_piece = file_offset // info.piece_length()
    priority_pieces = min(info.num_pieces() - first_piece, PRIORITY_WINDOW_PIECES)
    for offset in range(priority_pieces):
        handle.set_piece_deadline(first_piece + offset, (offset + 1) * 1000)

    # Known as soon as the torrent metadata is parsed, well before any bytes
    # land on disk. Reported from the first progress update (not just on
    # completion) so the streaming endpoint has a path to read from while the
    # download is still in progress.
    report(
        job_id,
        status="downloading",
        downloaded_bytes=0,
        total_bytes=file_size,
        is_complete=False,
        file_path=file_path,
    )

    last_reported = 0
    last_done = 0
    last_progress_at = time.time()
    status = handle.status()
    while not status.is_seeding:
        done = status.total_wanted_done
        if done > last_done:
            last_done = done
            last_progress_at = time.time()
        if done - last_reported >= REPORT_THRESHOLD_BYTES:
            report(
                job_id,
                status="downloading",
                downloaded_bytes=contiguous_bytes_done(status, info, target_index),
                total_bytes=file_size,
                is_complete=False,
            )
            last_reported = done
        if time.time() - last_progress_at > STALL_TIMEOUT_SECONDS:
            report(job_id, status="failed", message="download stalled: no progress and no peers available")
            return
        time.sleep(POLL_INTERVAL_SECONDS)
        status = handle.status()

    report(
        job_id,
        status="completed",
        downloaded_bytes=file_size,
        total_bytes=file_size,
        is_complete=True,
        file_path=file_path,
    )


def handle_job(job: dict) -> None:
    job_id = job["job_id"]
    job_type = job.get("type")

    if job_type == "ping":
        handle_ping(job_id)
    elif job_type == "download":
        handle_download(job_id, job["torrent_url"])
    else:
        report(job_id, status="failed", message=f"unknown job type: {job_type}")


def run_job(job: dict, job_id: str) -> None:
    try:
        handle_job(job)
        print(f"job {job_id} handled", flush=True)
    except Exception as exc:  # noqa: BLE001 - one bad job must not kill the worker
        print(f"job {job_id} raised {exc!r}", flush=True)
        try:
            report(job_id, status="failed", message=str(exc))
        except requests.RequestException as report_exc:
            print(f"job {job_id} could not report failure: {report_exc}", flush=True)


def main() -> None:
    client = connect_redis()
    print(f"worker ready, watching queue '{JOBS_QUEUE}'", flush=True)
    while True:
        item = client.brpop(JOBS_QUEUE, timeout=5)
        if item is None:
            continue
        _, raw_payload = item
        try:
            job = json.loads(raw_payload)
            job_id = job["job_id"]
        except (json.JSONDecodeError, KeyError) as exc:
            print(f"discarding malformed job payload {raw_payload!r}: {exc}", flush=True)
            continue
        # Laravel already caps how many downloads it hands out at once (see
        # DownloadScheduler); this loop just needs to not serialize them
        # behind one another, so each job runs in its own thread.
        threading.Thread(target=run_job, args=(job, job_id), daemon=True).start()


if __name__ == "__main__":
    main()

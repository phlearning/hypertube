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


def contiguous_bytes_done(status, info) -> int:
    """
    How many bytes, counted from the start of the file with no gap, are
    confirmed complete. This is deliberately NOT status.total_wanted_done:
    that's a sum of all completed pieces regardless of order, and
    sequential_download only biases piece *selection* — under a real
    multi-peer swarm a later piece can still finish before an earlier one,
    leaving a hole. The streaming endpoint trusts this value as "safe to
    read from byte 0"; reporting total_wanted_done would let it serve bytes
    from beyond a gap as if they were valid downloaded data.
    """
    complete_pieces = 0
    for piece_complete in status.pieces:
        if not piece_complete:
            break
        complete_pieces += 1

    if complete_pieces == 0:
        return 0
    if complete_pieces >= info.num_pieces():
        return info.total_size()

    return complete_pieces * info.piece_length()


def handle_download(job_id: str, torrent_url: str) -> None:
    try:
        torrent_response = requests.get(torrent_url, timeout=30)
        torrent_response.raise_for_status()
        info = lt.torrent_info(lt.bdecode(torrent_response.content))
    except Exception as exc:  # noqa: BLE001 - reported back as a failed job, not crashed worker
        report(job_id, status="failed", message=f"could not fetch torrent: {exc}")
        return

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

    # Front-load deadlines on the first pieces so libtorrent fetches the start
    # of the file with priority, on top of the sequential ordering above.
    priority_pieces = min(info.num_pieces(), PRIORITY_WINDOW_PIECES)
    for piece in range(priority_pieces):
        handle.set_piece_deadline(piece, (piece + 1) * 1000)

    # Known as soon as the torrent metadata is parsed, well before any bytes
    # land on disk. Reported from the first progress update (not just on
    # completion) so the streaming endpoint has a path to read from while the
    # download is still in progress.
    file_path = os.path.join(SHARED_DIR, info.name())

    report(
        job_id,
        status="downloading",
        downloaded_bytes=0,
        total_bytes=info.total_size(),
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
                downloaded_bytes=contiguous_bytes_done(status, info),
                total_bytes=status.total_wanted,
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
        downloaded_bytes=status.total_wanted,
        total_bytes=status.total_wanted,
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

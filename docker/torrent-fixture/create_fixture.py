import hashlib
import os

import libtorrent as lt

SHARED_DIR = "/shared"
FIXTURE_PATH = os.path.join(SHARED_DIR, "fixture.bin")
TORRENT_PATH = os.path.join(SHARED_DIR, "fixture.torrent")
ANNOUNCE_URL = os.environ.get("TRACKER_ANNOUNCE_URL", "http://torrent-fixture:6969/announce")
FIXTURE_SIZE = 3 * 1024 * 1024


def write_fixture_file() -> None:
    os.makedirs(SHARED_DIR, exist_ok=True)
    if os.path.exists(FIXTURE_PATH):
        return
    with open(FIXTURE_PATH, "wb") as f:
        chunk = hashlib.sha256(b"hypertube-fixture").digest()
        written = 0
        while written < FIXTURE_SIZE:
            f.write(chunk)
            written += len(chunk)
            chunk = hashlib.sha256(chunk).digest()


def create_torrent_file() -> None:
    if os.path.exists(TORRENT_PATH):
        return
    fs = lt.file_storage()
    lt.add_files(fs, FIXTURE_PATH)
    t = lt.create_torrent(fs, piece_size=32 * 1024)
    t.add_tracker(ANNOUNCE_URL)
    t.set_creator("hypertube-test-fixture")
    lt.set_piece_hashes(t, SHARED_DIR)
    with open(TORRENT_PATH, "wb") as f:
        f.write(lt.bencode(t.generate()))


if __name__ == "__main__":
    write_fixture_file()
    create_torrent_file()
    print("fixture ready:", FIXTURE_PATH, TORRENT_PATH, flush=True)

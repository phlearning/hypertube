import os
import time

import libtorrent as lt

SHARED_DIR = "/shared"
TORRENT_PATH = os.path.join(SHARED_DIR, "fixture.torrent")


def wait_for_torrent_file() -> None:
    while not os.path.exists(TORRENT_PATH):
        time.sleep(0.5)


def main() -> None:
    wait_for_torrent_file()
    session = lt.session(
        {
            "listen_interfaces": "0.0.0.0:6881",
            "enable_dht": False,
            "enable_lsd": False,
            "enable_natpmp": False,
            "enable_upnp": False,
        }
    )

    info = lt.torrent_info(TORRENT_PATH)
    handle = session.add_torrent(
        {
            "ti": info,
            "save_path": SHARED_DIR,
            "flags": lt.torrent_flags.seed_mode,
        }
    )

    print("seeding", info.name(), flush=True)
    while True:
        status = handle.status()
        print(f"seed status: peers={status.num_peers} uploaded={status.total_upload}", flush=True)
        time.sleep(10)


if __name__ == "__main__":
    main()

import socket
import struct
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote_to_bytes

TORRENT_PATH = "/shared/fixture.torrent"
PEER_TTL_SECONDS = 3600

# info_hash(bytes) -> {(ip, port): last_seen_timestamp}
SWARMS: dict[bytes, dict[tuple[str, int], float]] = {}


def bencode(value):
    if isinstance(value, int):
        return b"i" + str(value).encode() + b"e"
    if isinstance(value, bytes):
        return str(len(value)).encode() + b":" + value
    if isinstance(value, str):
        return bencode(value.encode())
    if isinstance(value, list):
        return b"l" + b"".join(bencode(v) for v in value) + b"e"
    if isinstance(value, dict):
        items = sorted(value.items())
        body = b"".join(bencode(k) + bencode(v) for k, v in items)
        return b"d" + body + b"e"
    raise TypeError(f"unbencodable type: {type(value)}")


_TORRENT_FILE_CACHE: bytes | None = None


def torrent_file_bytes() -> bytes:
    global _TORRENT_FILE_CACHE
    if _TORRENT_FILE_CACHE is None:
        with open(TORRENT_PATH, "rb") as f:
            _TORRENT_FILE_CACHE = f.read()
    return _TORRENT_FILE_CACHE


def parse_query(raw: bytes) -> dict[bytes, bytes]:
    params = {}
    for part in raw.split(b"&"):
        if not part:
            continue
        key, _, value = part.partition(b"=")
        params[unquote_to_bytes(key)] = unquote_to_bytes(value)
    return params


class TrackerHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        path, _, raw_query = self.path.partition("?")
        if path == "/announce":
            self.handle_announce(raw_query.encode())
        elif path == "/fixture.torrent":
            self.handle_torrent_file()
        else:
            self.send_error(404)

    def handle_announce(self, raw_query: bytes) -> None:
        params = parse_query(raw_query)
        info_hash = params.get(b"info_hash")
        peer_id = params.get(b"peer_id")
        try:
            port = int(params.get(b"port", b"0") or b"0")
        except ValueError:
            self.send_error(400, "invalid port")
            return
        if not info_hash or not peer_id or not port:
            self.send_error(400, "missing info_hash/peer_id/port")
            return

        ip = self.client_address[0]
        now = time.time()
        swarm = SWARMS.setdefault(info_hash, {})
        swarm[(ip, port)] = now
        for key, seen in list(swarm.items()):
            if now - seen > PEER_TTL_SECONDS:
                del swarm[key]
        if not swarm:
            del SWARMS[info_hash]

        compact_peers = b"".join(
            socket.inet_aton(peer_ip) + struct.pack(">H", peer_port)
            for (peer_ip, peer_port) in swarm
        )
        body = bencode({b"interval": 1800, b"peers": compact_peers})
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle_torrent_file(self) -> None:
        data = torrent_file_bytes()
        self.send_response(200)
        self.send_header("Content-Type", "application/x-bittorrent")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, format: str, *args) -> None:
        print(f"[tracker] {self.address_string()} {format % args}", flush=True)


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", 6969), TrackerHandler)
    print("tracker listening on :6969", flush=True)
    server.serve_forever()

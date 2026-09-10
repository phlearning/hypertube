import json
import os
import time

import redis
import requests

REDIS_HOST = os.environ.get("REDIS_HOST", "redis")
REDIS_PORT = int(os.environ.get("REDIS_PORT", "6379"))
JOBS_QUEUE = os.environ.get("TORRENT_JOBS_QUEUE", "torrent:jobs")
CALLBACK_URL = os.environ["LARAVEL_INTERNAL_URL"]
WORKER_SECRET = os.environ["TORRENT_WORKER_SECRET"]


def connect_redis() -> redis.Redis:
    client = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, decode_responses=True)
    while True:
        try:
            client.ping()
            return client
        except redis.exceptions.ConnectionError:
            print(f"waiting for redis at {REDIS_HOST}:{REDIS_PORT}...", flush=True)
            time.sleep(2)


def handle_job(job: dict) -> tuple[str, str]:
    if job.get("type") == "ping":
        return "completed", "pong from python worker"
    return "failed", f"unknown job type: {job.get('type')}"


def report(job_id: str, status: str, message: str) -> None:
    response = requests.post(
        CALLBACK_URL,
        json={"job_id": job_id, "status": status, "message": message},
        headers={"Authorization": f"Bearer {WORKER_SECRET}"},
        timeout=10,
    )
    response.raise_for_status()


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
            status, message = handle_job(job)
            report(job_id, status, message)
            print(f"job {job_id} -> {status}", flush=True)
        except (json.JSONDecodeError, KeyError) as exc:
            print(f"discarding malformed job payload {raw_payload!r}: {exc}", flush=True)
        except requests.RequestException as exc:
            print(f"job {job_id} callback failed: {exc}", flush=True)


if __name__ == "__main__":
    main()

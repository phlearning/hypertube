FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-pip python3-libtorrent \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /worker

COPY requirements.txt .
RUN pip install --no-cache-dir --break-system-packages -r requirements.txt

COPY worker.py .

CMD ["python3", "worker.py"]

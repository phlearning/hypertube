FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-libtorrent \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /fixture

COPY create_fixture.py tracker_server.py seed.py entrypoint.sh ./
RUN chmod +x entrypoint.sh

EXPOSE 6969 6881

ENTRYPOINT ["./entrypoint.sh"]

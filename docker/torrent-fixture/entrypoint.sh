#!/bin/sh
set -e

python3 create_fixture.py
python3 tracker_server.py &
exec python3 seed.py

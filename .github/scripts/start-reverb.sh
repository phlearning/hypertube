#!/usr/bin/env bash
# Starts Reverb as a background process and records its PID, so the e2e
# workflow's E2E_REVERB_STOP_CMD/E2E_REVERB_START_CMD (see
# e2e/support/infra.ts) can stop and restart it for the broadcast-outage
# scenario without docker-compose, which this CI job doesn't use.
set -euo pipefail

nohup php artisan reverb:start --host=0.0.0.0 --port=8080 > reverb.log 2>&1 &
echo $! > reverb.pid

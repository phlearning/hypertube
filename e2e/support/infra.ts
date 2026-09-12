import { execSync } from 'node:child_process';

/**
 * Stopping/restarting Reverb for the broadcast-outage scenario. Defaults to
 * docker-compose (the local dev setup); override in CI or any non-docker
 * environment with E2E_REVERB_STOP_CMD / E2E_REVERB_START_CMD.
 */
const STOP_CMD = process.env.E2E_REVERB_STOP_CMD ?? 'docker compose stop reverb';
const START_CMD = process.env.E2E_REVERB_START_CMD ?? 'docker compose start reverb';

export function stopReverb(): void {
    execSync(STOP_CMD, { stdio: 'pipe' });
}

export function startReverb(): void {
    execSync(START_CMD, { stdio: 'pipe' });
}

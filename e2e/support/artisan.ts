import { execFileSync } from 'node:child_process';

/**
 * How to reach `artisan` for this run. Defaults to the local docker-compose
 * dev setup; override in CI (or any environment without docker-compose) by
 * setting E2E_ARTISAN_CMD to e.g. "php artisan".
 */
const ARTISAN_CMD = process.env.E2E_ARTISAN_CMD ?? 'docker compose exec -T app php artisan';

/**
 * Filesystem root the artisan process above sees as its app directory —
 * `/var/www/html` inside the docker-compose `app` container, but the
 * checkout directory itself when artisan runs as a bare process (e.g. the
 * CI `e2e` job, which sets E2E_APP_ROOT to $GITHUB_WORKSPACE). Any test
 * writing a file for the app to read/delete needs to store the path this
 * way rather than hardcoding either environment's layout.
 */
export const APP_ROOT = process.env.E2E_APP_ROOT ?? '/var/www/html';

function runArtisan(args: string[]): string {
    const [command, ...prefixArgs] = ARTISAN_CMD.split(' ');

    return execFileSync(command, [...prefixArgs, ...args], { encoding: 'utf-8' });
}

/** Clears torrent_jobs and ensures the known e2e-user/e2e-admin accounts exist. */
export function resetTestData(): void {
    runArtisan(['e2e:reset']);
}

export type TorrentJobRecord = {
    id: number;
    job_id: string;
    type: string;
    title: string | null;
    status: string;
    downloaded_bytes: number;
    total_bytes: number | null;
    file_path: string | null;
    playback_path: string | null;
    transcode_status: string | null;
    last_watched_at: string | null;
    [key: string]: unknown;
};

export function makeTorrentJob(overrides: Record<string, unknown> = {}): TorrentJobRecord {
    const output = runArtisan(['e2e:make-torrent-job', JSON.stringify(overrides)]);

    return JSON.parse(output.trim());
}

export function updateTorrentJob(jobId: string, attributes: Record<string, unknown>): TorrentJobRecord {
    const output = runArtisan(['e2e:update-torrent-job', jobId, JSON.stringify(attributes)]);

    return JSON.parse(output.trim());
}

/**
 * Applies every update back to back inside a single artisan process — a
 * real burst happens in milliseconds, which shelling out per-update (each
 * paying its own `docker compose exec` + artisan boot cost) cannot
 * reproduce closely enough to exercise a 1-second throttle honestly.
 */
export function burstUpdateTorrentJob(jobId: string, updates: Record<string, unknown>[]): TorrentJobRecord {
    const output = runArtisan(['e2e:burst-update-torrent-job', jobId, JSON.stringify(updates)]);

    return JSON.parse(output.trim());
}

/**
 * Pre-populates MovieSearchService's result cache for the given query, so
 * /library shows exactly these movies instead of live archive.org /
 * PublicDomainTorrents / OMDb results — deterministic, and independent of
 * whatever those services return (or whether they're reachable at all) on
 * the day the suite runs.
 */
export function seedSearchResults(query: string, movies: Record<string, unknown>[]): void {
    runArtisan(['e2e:seed-search-cache', JSON.stringify(movies), `--query=${query}`]);
}

/**
 * Reads a config value from the app itself (dot notation) — used to get the
 * internal worker's shared secret for broadcast-outage.spec.ts's call to the
 * real completion callback, rather than hardcoding or duplicating it.
 */
export function readConfig(key: string): string {
    return runArtisan(['e2e:print-config', key]).trim();
}

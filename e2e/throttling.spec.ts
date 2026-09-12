import { test, expect } from '@playwright/test';
import { burstUpdateTorrentJob, makeTorrentJob, updateTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';

test.use({ storageState: USER_AUTH });

test('a burst of byte-progress updates only reaches the UI at the throttled rate, never a stale value beyond it', async ({ page }) => {
    const torrentJob = makeTorrentJob({ status: 'downloading', downloaded_bytes: 0, total_bytes: 1000 });

    await page.goto(`/library/downloads/${torrentJob.id}`);
    await expect(page.getByText('0%')).toBeVisible();

    // show.tsx does a one-off catch-up `router.reload()` once Echo's private
    // channel subscription settles, to close the auth-handshake race (see
    // channel-auth-race.spec.ts). That reload's own timing is otherwise
    // unbounded and would race the burst below — settling here first keeps
    // this test about the throttle, not about that unrelated race.
    await page.waitForTimeout(500);

    // A burst of rapid byte-progress updates within the same throttle
    // window (1s) — only the first should broadcast; the rest are silently
    // dropped server-side, not queued for later delivery.
    burstUpdateTorrentJob(torrentJob.job_id, [
        { downloaded_bytes: 100 },
        { downloaded_bytes: 250 },
        { downloaded_bytes: 400 },
    ]);

    // The UI should reflect the first broadcast (10%) shortly after, not
    // jump straight to a later, more-recent value it never received —
    // exactly what "throttled" means from the client's point of view.
    await expect(page.getByText('10%')).toBeVisible({ timeout: 5_000 });
    await expect(page.getByText('40%')).not.toBeVisible();

    // Once the throttle window elapses, a further update broadcasts and the
    // UI catches up to the latest state.
    await page.waitForTimeout(1_100);
    updateTorrentJob(torrentJob.job_id, { downloaded_bytes: 700 });

    await expect(page.getByText('70%')).toBeVisible({ timeout: 5_000 });
});

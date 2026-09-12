import { test, expect } from '@playwright/test';
import { makeTorrentJob, updateTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';

test.use({ storageState: USER_AUTH });

test('a job completing right as the page loads still shows the final state', async ({ page }) => {
    const torrentJob = makeTorrentJob({ status: 'downloading', downloaded_bytes: 50, total_bytes: 100 });

    const navigation = page.goto(`/library/downloads/${torrentJob.id}`);

    // Racing the page's own load/subscribe against the job reaching a
    // terminal state — the exact interleaving is up to the scheduler, which
    // is the point: whichever wins, the page must never get stuck showing
    // "downloading" forever. The one-off catch-up reload on Echo's
    // `subscribed()` callback exists precisely to close this window.
    updateTorrentJob(torrentJob.job_id, { status: 'completed', downloaded_bytes: 100, is_complete: true });

    await navigation;

    await expect(page.getByText('Terminé')).toBeVisible({ timeout: 10_000 });
});

import { test, expect } from '@playwright/test';
import { makeTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';

test.use({ storageState: USER_AUTH });

test('a failed download with bytes already on disk shows the failure state, never a video player', async ({ page }) => {
    // Mirrors the real "About Bananas" regression: a download that reached
    // 99.8% before the swarm died, leaving a same-size-but-corrupt file
    // (moov atom missing) — canPlay() must not be fooled by the byte count.
    const torrentJob = makeTorrentJob({
        title: 'About Bananas',
        status: 'failed',
        downloaded_bytes: 211163830,
        total_bytes: 211573767,
        file_path: '/shared/AboutBan1935/AboutBan1935_edit.mp4',
        message: 'download stalled: no progress and no peers available',
    });

    await page.goto(`/library/downloads/${torrentJob.id}`);

    await expect(page.getByText('Échec')).toBeVisible();
    await expect(page.getByText("Le téléchargement a échoué : la lecture n'est pas possible.")).toBeVisible();
    await expect(page.locator('video')).toHaveCount(0);
});

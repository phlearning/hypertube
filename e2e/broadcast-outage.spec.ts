import { test, expect } from '@playwright/test';
import { makeTorrentJob, updateTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';
import { startReverb, stopReverb } from './support/infra';

test.use({ storageState: USER_AUTH });

test('the download keeps progressing server-side even while Reverb is down', async ({ page }) => {
    const torrentJob = makeTorrentJob({ status: 'downloading', downloaded_bytes: 0, total_bytes: 100 });

    await page.goto(`/library/downloads/${torrentJob.id}`);
    await expect(page.getByText('Téléchargement en cours')).toBeVisible();

    stopReverb();

    try {
        // The update itself must not fail just because nothing is listening
        // for the broadcast — TorrentJob::broadcastProgress() catches
        // broadcast failures precisely so this keeps working.
        const updated = updateTorrentJob(torrentJob.job_id, {
            status: 'completed',
            downloaded_bytes: 100,
            is_complete: true,
        });

        expect(updated.status).toBe('completed');
        expect(updated.is_complete).toBe(true);

        // The client never learns about it in real time — no retry, no
        // fallback poll — since the whole point of this scenario is that a
        // Reverb outage is invisible to the already-connected page.
        await page.waitForTimeout(1000);
        await expect(page.getByText('Téléchargement en cours')).toBeVisible();
    } finally {
        startReverb();
    }

    // Once Reverb is back and the page is revisited, the server-side state
    // (which kept progressing throughout) is what the user sees.
    await page.reload();
    await expect(page.getByText('Terminé')).toBeVisible();
});

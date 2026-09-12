import { test, expect } from '@playwright/test';
import { makeTorrentJob, readConfig, resetTestData, updateTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';
import { startReverb, stopReverb } from './support/infra';

test.use({ storageState: USER_AUTH });

test.beforeEach(() => {
    // The slot-release assertion below depends on exact concurrency-cap
    // counts (DownloadScheduler::MAX_CONCURRENT_DOWNLOADS = 3), so this
    // needs a clean slate rather than whatever active jobs earlier
    // scenarios left behind in the shared dev database.
    resetTestData();
});

test('the download keeps progressing server-side even while Reverb is down', async ({ page }) => {
    const watched = makeTorrentJob({ status: 'downloading', downloaded_bytes: 0, total_bytes: 100 });
    makeTorrentJob({ status: 'downloading' });
    makeTorrentJob({ status: 'downloading' });
    const queued = makeTorrentJob({ status: 'queued' });

    await page.goto(`/library/downloads/${watched.id}`);
    await expect(page.getByText('Téléchargement en cours')).toBeVisible();

    stopReverb();

    try {
        const secret = readConfig('services.torrent_worker.secret');

        // The real internal completion callback, not a raw DB write — this
        // genuinely exercises DownloadScheduler::handleCompleted() (slot
        // release + FIFO promotion) and the TranscodeVideo dispatch, not
        // just a status field round-trip through a test-only helper.
        const response = await page.request.post('/internal/torrent-worker/callback', {
            headers: { Authorization: `Bearer ${secret}` },
            data: {
                job_id: watched.job_id,
                status: 'completed',
                downloaded_bytes: 100,
                total_bytes: 100,
                is_complete: true,
                file_path: '/shared/e2e/fake.mp4',
            },
        });

        // The callback itself must not fail just because nothing is
        // listening for the broadcast — TorrentJob::broadcastProgress()
        // catches broadcast failures precisely so this keeps working.
        expect(response.ok()).toBe(true);

        // The client never learns about it in real time — no retry, no
        // fallback poll — since the whole point of this scenario is that a
        // Reverb outage is invisible to the already-connected page.
        await page.waitForTimeout(1000);
        await expect(page.getByText('Téléchargement en cours')).toBeVisible();

        // The concurrency slot the completed job held is freed and the
        // queued job promoted ("slot libéré" from the ticket) — proving the
        // scheduler kept working server-side despite the broadcast failure.
        // updateTorrentJob with no attributes is a no-op write, used here
        // purely to read the row back fresh. Asserting "no longer queued"
        // rather than "pending" specifically: this stack runs a real
        // redis-backed queue worker, which can pick up the dispatched
        // StartTorrentDownload job and fail it against this job's
        // fake/unreachable torrent_url before this read happens — a real
        // race with genuine background processing, not a flaw in the
        // promotion this test is actually checking for.
        const promoted = updateTorrentJob(queued.job_id, {});
        expect(promoted.status).not.toBe('queued');
    } finally {
        startReverb();
    }

    // Once Reverb is back and the page is revisited, the server-side state
    // (which kept progressing throughout) is what the user sees.
    await page.reload();
    await expect(page.getByText('Terminé')).toBeVisible();
});

import { test, expect } from '@playwright/test';
import { makeTorrentJob, updateTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';

test('two tabs watching the same download both update from a single broadcast', async ({ browser }) => {
    const torrentJob = makeTorrentJob({ status: 'downloading', downloaded_bytes: 0, total_bytes: 100 });

    const context = await browser.newContext({ storageState: USER_AUTH });
    const tabA = await context.newPage();
    const tabB = await context.newPage();

    await tabA.goto(`/library/downloads/${torrentJob.id}`);
    await tabB.goto(`/library/downloads/${torrentJob.id}`);

    await expect(tabA.getByText('0%')).toBeVisible();
    await expect(tabB.getByText('0%')).toBeVisible();

    updateTorrentJob(torrentJob.job_id, { downloaded_bytes: 55 });

    await expect(tabA.getByText('55%')).toBeVisible({ timeout: 5_000 });
    await expect(tabB.getByText('55%')).toBeVisible({ timeout: 5_000 });

    await context.close();
});

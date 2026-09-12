import { test, expect } from '@playwright/test';
import { makeTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';

test.use({ storageState: USER_AUTH });

test('the broadcasting auth endpoint rejects a request with no valid session', async ({ page }) => {
    const torrentJob = makeTorrentJob({ status: 'downloading' });

    await page.goto(`/library/downloads/${torrentJob.id}`);

    // Simulates the session expiring while the page is already open: no
    // cookies at all is the simplest, most deterministic way to reproduce
    // "the next channel (re)subscription is unauthenticated", independent of
    // exactly how or when a real session would expire. Using page.request
    // (not the top-level `request` fixture) matters here: it shares the
    // page's own cookie jar, so clearing it here is actually reflected.
    await page.context().clearCookies();

    const response = await page.request.post('/broadcasting/auth', {
        form: { channel_name: `private-torrent-job.${torrentJob.id}`, socket_id: '1.1' },
        failOnStatusCode: false,
    });

    // Currently unhandled client-side beyond a console.error (see echo.ts's
    // `.error()` callback on the channel) — this test documents that the
    // server correctly refuses the subscription; it does not assert any
    // user-visible error UI, because none exists yet.
    expect(response.status()).toBeGreaterThanOrEqual(400);
    expect(response.status()).toBeLessThan(500);
});

test('reloading the download page after the session is gone redirects to login', async ({ page }) => {
    const torrentJob = makeTorrentJob({ status: 'downloading' });

    await page.goto(`/library/downloads/${torrentJob.id}`);

    await page.context().clearCookies();
    await page.reload();

    await expect(page).toHaveURL(/\/login$/);
});

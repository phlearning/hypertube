import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { APP_ROOT, makeTorrentJob, resetTestData, updateTorrentJob } from './support/artisan';
import { USER_AUTH } from './support/auth';

test.use({ storageState: USER_AUTH });

test.beforeEach(() => {
    resetTestData();
});

/**
 * Copies the tiny synthetic webm fixture into storage/app/e2e-tmp — same
 * convention as admin-cleanup.spec.ts — and returns its container-side path
 * plus real byte size. webm ('skip' plan) is the only format canPlay() lets
 * through before a download finishes (see ADR 0002 and
 * TorrentJobPresenterTest), so it's the only format that can reach the
 * player mid-download at all.
 */
function seedSampleWebm(): { filePath: string; totalBytes: number } {
    const tmpDir = path.join(process.cwd(), 'storage', 'app', 'e2e-tmp');
    fs.mkdirSync(tmpDir, { recursive: true });

    const destination = path.join(tmpDir, `sample-${Date.now()}.webm`);
    fs.copyFileSync(path.join(process.cwd(), 'e2e', 'fixtures', 'sample.webm'), destination);

    return {
        filePath: path.posix.join(APP_ROOT, 'storage', 'app', 'e2e-tmp', path.basename(destination)),
        totalBytes: fs.statSync(destination).size,
    };
}

test('seeking past the downloaded portion of a still-downloading video is clamped back', async ({ page }) => {
    const { filePath, totalBytes } = seedSampleWebm();

    const torrentJob = makeTorrentJob({
        title: 'Boundary Seek',
        status: 'downloading',
        file_path: filePath,
        downloaded_bytes: Math.floor(totalBytes / 2),
        total_bytes: totalBytes,
    });

    await page.goto(`/library/downloads/${torrentJob.id}`, { waitUntil: 'domcontentloaded' });

    const video = page.locator('video');
    await expect(video).toBeVisible();
    await expect(
        page.getByText("vous ne pouvez avancer que jusqu'à environ 50%"),
    ).toBeVisible();

    // Actually decoding a genuinely partial webm to get a real `duration`
    // is exactly the failure mode this ticket fixes elsewhere (see the
    // `preload` change in show.tsx) — Chromium's demuxer can hang for a
    // very long time hunting for the trailing index on a file that's
    // missing even a few bytes from the end. Stubbing `duration` and
    // `currentTime` exercises the real onSeeking handler against a known
    // value instead of fighting the browser's media pipeline for something
    // unrelated to the clamp logic under test.
    const clampedTime = await video.evaluate((element: HTMLVideoElement) => {
        Object.defineProperty(element, 'duration', { value: 100, configurable: true });

        let currentTime = 95;
        Object.defineProperty(element, 'currentTime', {
            configurable: true,
            get: () => currentTime,
            set: (value: number) => {
                currentTime = value;
            },
        });

        element.dispatchEvent(new Event('seeking'));

        return currentTime;
    });

    // Half the file is downloaded over a 100s duration, minus the 2s safety
    // margin — the handler should have snapped back to ~48s, not left the
    // seek at 95s.
    expect(clampedTime).toBeLessThan(60);
    expect(clampedTime).toBeGreaterThan(40);
});

test('playback recovers once more bytes arrive after stalling at the downloaded boundary', async ({ page }) => {
    const { filePath, totalBytes } = seedSampleWebm();

    const torrentJob = makeTorrentJob({
        title: 'Boundary Stall Recovery',
        status: 'downloading',
        file_path: filePath,
        downloaded_bytes: Math.floor(totalBytes * 0.35),
        total_bytes: totalBytes,
    });

    await page.goto(`/library/downloads/${torrentJob.id}`, { waitUntil: 'domcontentloaded' });

    const video = page.locator('video');
    await expect(video).toBeVisible();

    // Same rationale as above: stub duration/currentTime and spy on play()
    // rather than driving a real (and, for a partial webm, potentially very
    // slow) media decode — this is testing the retry wiring around a stall,
    // not Chromium's demuxer.
    await video.evaluate((element: HTMLVideoElement) => {
        Object.defineProperty(element, 'duration', { value: 100, configurable: true });
        Object.defineProperty(element, 'currentTime', { value: 34, configurable: true });

        let playCalls = 0;
        element.play = () => {
            playCalls += 1;

            return Promise.resolve();
        };
        (window as unknown as { __playCalls: number }).__playCalls = 0;
        Object.defineProperty(window, '__playCalls', {
            configurable: true,
            get: () => playCalls,
        });

        // ratio 0.35 * 100s duration - 2s margin = 33s: currentTime (34) is
        // within the margin of that boundary, so this counts as "stalled
        // near the edge" rather than an unrelated stall.
        element.dispatchEvent(new Event('waiting'));
    });

    // The rest of the file "arrives" — a real update, broadcast over Reverb
    // exactly like a genuine worker progress callback would be.
    updateTorrentJob(torrentJob.job_id, { downloaded_bytes: totalBytes });

    await expect
        .poll(() => page.evaluate(() => (window as unknown as { __playCalls: number }).__playCalls))
        .toBeGreaterThan(0);
});

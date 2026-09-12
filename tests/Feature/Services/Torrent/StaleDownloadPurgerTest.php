<?php

use App\Models\TorrentJob;
use App\Services\Torrent\StaleDownloadPurger;

function purger(): StaleDownloadPurger
{
    return app(StaleDownloadPurger::class);
}

function makeCompletedDownload(array $overrides = []): TorrentJob
{
    $filePath = tempnam(sys_get_temp_dir(), 'hypertube-source');
    $torrentJob = makeTorrentJob(array_merge([
        'status' => 'completed',
        'file_path' => $filePath,
    ], $overrides));
    $torrentJob->timestamps = false;

    return $torrentJob;
}

test('a completed download last watched over a month ago is purged', function () {
    $torrentJob = makeCompletedDownload(['last_watched_at' => now()->subMonths(2)]);

    $purged = purger()->purge();

    expect($purged)->toBe(1);
    $fresh = $torrentJob->fresh();
    expect($fresh->file_path)->toBeNull()
        ->and($fresh->status)->toBe('completed')
        ->and(TorrentJob::count())->toBe(1);
});

test('purging deletes the file from disk', function () {
    $torrentJob = makeCompletedDownload(['last_watched_at' => now()->subMonths(2)]);
    $filePath = $torrentJob->file_path;

    purger()->purge();

    expect(file_exists($filePath))->toBeFalse();
});

test('purging also clears playback_path, transcode_status, and media_info', function () {
    $playbackPath = tempnam(sys_get_temp_dir(), 'hypertube-playback');
    $torrentJob = makeCompletedDownload([
        'last_watched_at' => now()->subMonths(2),
        'playback_path' => $playbackPath,
        'transcode_status' => 'completed',
        'media_info' => ['width' => 1920, 'height' => 1080],
    ]);

    purger()->purge();

    $fresh = $torrentJob->fresh();
    expect($fresh->playback_path)->toBeNull()
        ->and($fresh->transcode_status)->toBeNull()
        ->and($fresh->media_info)->toBeNull();
});

test('a completed download watched recently is left untouched', function () {
    $torrentJob = makeCompletedDownload(['last_watched_at' => now()->subDays(5)]);

    $purged = purger()->purge();

    expect($purged)->toBe(0);
    expect($torrentJob->fresh()->file_path)->not->toBeNull();
});

test('a completed download that was never watched is purged based on when it finished', function () {
    $torrentJob = makeCompletedDownload(['last_watched_at' => null]);
    $torrentJob->forceFill(['updated_at' => now()->subMonths(2)])->save();

    $purged = purger()->purge();

    expect($purged)->toBe(1);
});

test('a completed download that finished recently and was never watched is left untouched', function () {
    makeCompletedDownload(['last_watched_at' => null]);

    $purged = purger()->purge();

    expect($purged)->toBe(0);
});

test('an active (non-completed) download is never purged, no matter how old', function () {
    $torrentJob = makeCompletedDownload(['status' => 'downloading', 'last_watched_at' => now()->subMonths(2)]);

    $purged = purger()->purge();

    expect($purged)->toBe(0);
    expect($torrentJob->fresh()->file_path)->not->toBeNull();
});

test('an already-purged download (no file_path) is not counted again', function () {
    makeCompletedDownload(['file_path' => null, 'last_watched_at' => now()->subMonths(2)]);

    $purged = purger()->purge();

    expect($purged)->toBe(0);
});

test('a ping job is never purged', function () {
    $torrentJob = makeTorrentJob(['type' => 'ping', 'status' => 'completed', 'file_path' => '/shared/x.mp4']);
    $torrentJob->forceFill(['updated_at' => now()->subMonths(2)])->save();

    $purged = purger()->purge();

    expect($purged)->toBe(0);
});

<?php

use App\Enums\TorrentJobClearScope;
use App\Models\TorrentJob;
use App\Services\Torrent\TorrentFileDeleter;
use App\Services\Torrent\TorrentJobCleaner;

function cleaner(): TorrentJobCleaner
{
    return new TorrentJobCleaner(new TorrentFileDeleter);
}

test('scope "all" deletes every torrent job regardless of status or type', function () {
    makeTorrentJob(['status' => 'completed']);
    makeTorrentJob(['status' => 'downloading']);
    makeTorrentJob(['type' => 'ping', 'status' => 'completed']);

    $deleted = cleaner()->clear(TorrentJobClearScope::All, deleteFiles: false);

    expect($deleted)->toBe(3)
        ->and(TorrentJob::count())->toBe(0);
});

test('scope "stuck" deletes failed jobs regardless of age', function () {
    $failed = makeTorrentJob(['status' => 'failed']);
    $completed = makeTorrentJob(['status' => 'completed']);

    $deleted = cleaner()->clear(TorrentJobClearScope::Stuck, deleteFiles: false);

    expect($deleted)->toBe(1);
    expect(TorrentJob::find($failed->id))->toBeNull();
    expect(TorrentJob::find($completed->id))->not->toBeNull();
});

test('scope "stuck" deletes active jobs that have gone quiet for a while', function () {
    $stale = makeTorrentJob(['status' => 'downloading']);
    $stale->timestamps = false;
    $stale->forceFill(['updated_at' => now()->subHour()])->save();

    $fresh = makeTorrentJob(['status' => 'downloading']);

    $deleted = cleaner()->clear(TorrentJobClearScope::Stuck, deleteFiles: false);

    expect($deleted)->toBe(1);
    expect(TorrentJob::find($stale->id))->toBeNull();
    expect(TorrentJob::find($fresh->id))->not->toBeNull();
});

test('scope "stuck" leaves completed jobs alone', function () {
    makeTorrentJob(['status' => 'completed']);

    $deleted = cleaner()->clear(TorrentJobClearScope::Stuck, deleteFiles: false);

    expect($deleted)->toBe(0)
        ->and(TorrentJob::count())->toBe(1);
});

test('deleteFiles removes file_path and playback_path from disk', function () {
    $filePath = tempnam(sys_get_temp_dir(), 'hypertube-source');
    $playbackPath = tempnam(sys_get_temp_dir(), 'hypertube-playback');
    makeTorrentJob(['status' => 'completed', 'file_path' => $filePath, 'playback_path' => $playbackPath]);

    cleaner()->clear(TorrentJobClearScope::All, deleteFiles: true);

    expect(file_exists($filePath))->toBeFalse()
        ->and(file_exists($playbackPath))->toBeFalse();
});

test('deleteFiles=false leaves files on disk untouched', function () {
    $filePath = tempnam(sys_get_temp_dir(), 'hypertube-source');
    makeTorrentJob(['status' => 'completed', 'file_path' => $filePath]);

    cleaner()->clear(TorrentJobClearScope::All, deleteFiles: false);

    expect(file_exists($filePath))->toBeTrue();
    @unlink($filePath);
});

test('a missing file on disk does not throw when deleteFiles is true', function () {
    makeTorrentJob(['status' => 'completed', 'file_path' => '/nonexistent/path/movie.mp4']);

    $deleted = cleaner()->clear(TorrentJobClearScope::All, deleteFiles: true);

    expect($deleted)->toBe(1);
});

<?php

use App\Services\Torrent\TorrentFileDeleter;

test('it deletes both file_path and playback_path when present', function () {
    $filePath = tempnam(sys_get_temp_dir(), 'hypertube-source');
    $playbackPath = tempnam(sys_get_temp_dir(), 'hypertube-playback');
    $torrentJob = makeTorrentJob(['file_path' => $filePath, 'playback_path' => $playbackPath]);

    (new TorrentFileDeleter)->delete($torrentJob, 'TestContext');

    expect(file_exists($filePath))->toBeFalse()
        ->and(file_exists($playbackPath))->toBeFalse();
});

test('a null path is skipped without error', function () {
    $torrentJob = makeTorrentJob(['file_path' => null, 'playback_path' => null]);

    (new TorrentFileDeleter)->delete($torrentJob, 'TestContext');
})->throwsNoExceptions();

test('a missing file on disk does not throw', function () {
    $torrentJob = makeTorrentJob(['file_path' => '/nonexistent/path/movie.mp4']);

    (new TorrentFileDeleter)->delete($torrentJob, 'TestContext');
})->throwsNoExceptions();

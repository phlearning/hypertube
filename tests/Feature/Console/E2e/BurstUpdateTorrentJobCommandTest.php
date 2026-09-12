<?php

test('it applies each update in order within a single process', function () {
    $torrentJob = makeTorrentJob(['status' => 'downloading', 'downloaded_bytes' => 0]);

    $this->artisan('e2e:burst-update-torrent-job', [
        'job_id' => $torrentJob->job_id,
        'updates' => json_encode([
            ['downloaded_bytes' => 100],
            ['downloaded_bytes' => 250],
            ['downloaded_bytes' => 400],
        ]),
    ])->assertSuccessful();

    expect($torrentJob->fresh()->downloaded_bytes)->toBe(400);
});

test('an unknown job_id fails', function () {
    $this->artisan('e2e:burst-update-torrent-job', [
        'job_id' => 'does-not-exist',
        'updates' => '[]',
    ])->assertFailed();
});

test('it refuses to run in production', function () {
    $torrentJob = makeTorrentJob(['status' => 'downloading', 'downloaded_bytes' => 0]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('e2e:burst-update-torrent-job', [
        'job_id' => $torrentJob->job_id,
        'updates' => json_encode([['downloaded_bytes' => 100]]),
    ])->assertFailed();

    expect($torrentJob->fresh()->downloaded_bytes)->toBe(0);
});

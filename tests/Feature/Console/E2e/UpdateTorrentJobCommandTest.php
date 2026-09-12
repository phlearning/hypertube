<?php

test('it updates a torrent job found by job_id', function () {
    $torrentJob = makeTorrentJob(['status' => 'downloading']);

    $this->artisan('e2e:update-torrent-job', [
        'job_id' => $torrentJob->job_id,
        'attributes' => json_encode(['status' => 'completed']),
    ])->assertSuccessful();

    expect($torrentJob->fresh()->status)->toBe('completed');
});

test('an unknown job_id fails', function () {
    $this->artisan('e2e:update-torrent-job', [
        'job_id' => 'does-not-exist',
        'attributes' => '{}',
    ])->assertFailed();
});

test('it refuses to run in production', function () {
    $torrentJob = makeTorrentJob(['status' => 'downloading']);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('e2e:update-torrent-job', [
        'job_id' => $torrentJob->job_id,
        'attributes' => json_encode(['status' => 'completed']),
    ])->assertFailed();

    expect($torrentJob->fresh()->status)->toBe('downloading');
});

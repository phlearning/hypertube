<?php

use App\Jobs\StartTorrentDownload;
use App\Services\Torrent\DownloadScheduler;
use Illuminate\Support\Facades\Queue;

test('admit starts the job immediately when under the concurrency cap', function () {
    Queue::fake();
    $job = makeTorrentJob();

    (new DownloadScheduler)->admit($job);

    expect($job->fresh()->status)->toBe('pending');
    Queue::assertPushed(StartTorrentDownload::class);
});

test('admit leaves the job queued when the concurrency cap is already reached', function () {
    Queue::fake();
    makeTorrentJob(['status' => 'pending']);
    makeTorrentJob(['status' => 'downloading']);
    makeTorrentJob(['status' => 'pending']);
    $fourth = makeTorrentJob();

    (new DownloadScheduler)->admit($fourth);

    expect($fourth->fresh()->status)->toBe('queued');
    Queue::assertNotPushed(StartTorrentDownload::class);
});

test('handleFailedAttempt advances to the next candidate when one remains', function () {
    Queue::fake();
    $job = makeTorrentJob([
        'status' => 'downloading',
        'torrent_url' => 'http://source-a.test/movie.torrent',
        'info_hash' => 'aaaa',
        'remaining_candidates' => [
            ['source' => 'b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent', 'info_hash' => 'bbbb'],
        ],
    ]);

    $advanced = (new DownloadScheduler)->handleFailedAttempt($job, '0 seeders');

    expect($advanced)->toBeTrue();
    expect($job->fresh())
        ->status->toBe('pending')
        ->torrent_url->toBe('http://source-b.test/movie.torrent')
        ->source->toBe('b')
        ->info_hash->toBe('aaaa')
        ->remaining_candidates->toBe([]);
    Queue::assertPushed(StartTorrentDownload::class);
});

test('handleFailedAttempt clears file_path/downloaded_bytes/total_bytes/is_complete, since they described the failed candidate\'s file', function () {
    Queue::fake();
    $job = makeTorrentJob([
        'status' => 'downloading',
        'file_path' => '/shared/candidate-a.mkv',
        'downloaded_bytes' => 40 * 1024 * 1024,
        'total_bytes' => 100 * 1024 * 1024,
        'is_complete' => false,
        'remaining_candidates' => [
            ['source' => 'b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent', 'info_hash' => 'bbbb'],
        ],
    ]);

    (new DownloadScheduler)->handleFailedAttempt($job, '0 seeders');

    expect($job->fresh())
        ->file_path->toBeNull()
        ->downloaded_bytes->toBe(0)
        ->total_bytes->toBeNull()
        ->is_complete->toBeFalse();
});

test('handleFailedAttempt keeps info_hash unchanged so dedup keeps matching this job across fallbacks', function () {
    Queue::fake();
    $job = makeTorrentJob([
        'info_hash' => 'aaaa',
        'remaining_candidates' => [
            ['source' => 'b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent', 'info_hash' => 'bbbb'],
        ],
    ]);

    (new DownloadScheduler)->handleFailedAttempt($job, 'stalled');

    expect($job->fresh()->info_hash)->toBe('aaaa');
});

test('handleFailedAttempt marks the job failed once every candidate is exhausted', function () {
    Queue::fake();
    $job = makeTorrentJob(['status' => 'downloading', 'remaining_candidates' => []]);

    $advanced = (new DownloadScheduler)->handleFailedAttempt($job, 'no peers');

    expect($advanced)->toBeFalse();
    expect($job->fresh())
        ->status->toBe('failed')
        ->message->toBe('no peers');
    Queue::assertNotPushed(StartTorrentDownload::class);
});

test('handleFailedAttempt records the failed candidate in attempted_candidates', function () {
    Queue::fake();
    $job = makeTorrentJob([
        'source' => 'a',
        'torrent_url' => 'http://source-a.test/movie.torrent',
        'info_hash' => 'aaaa',
        'remaining_candidates' => [
            ['source' => 'b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent', 'info_hash' => 'bbbb'],
        ],
    ]);

    (new DownloadScheduler)->handleFailedAttempt($job, '0 seeders');

    expect($job->fresh()->attempted_candidates)->toBe([
        ['source' => 'a', 'torrent_url' => 'http://source-a.test/movie.torrent', 'message' => '0 seeders'],
    ]);
});

test('handleFailedAttempt appends to existing attempted_candidates across multiple fallbacks', function () {
    Queue::fake();
    $job = makeTorrentJob([
        'source' => 'b',
        'torrent_url' => 'http://source-b.test/movie.torrent',
        'attempted_candidates' => [
            ['source' => 'a', 'torrent_url' => 'http://source-a.test/movie.torrent', 'message' => '0 seeders'],
        ],
        'remaining_candidates' => [
            ['source' => 'c', 'source_id' => '3', 'torrent_url' => 'http://source-c.test/movie.torrent', 'info_hash' => 'cccc'],
        ],
    ]);

    (new DownloadScheduler)->handleFailedAttempt($job, 'timed out');

    expect($job->fresh()->attempted_candidates)->toBe([
        ['source' => 'a', 'torrent_url' => 'http://source-a.test/movie.torrent', 'message' => '0 seeders'],
        ['source' => 'b', 'torrent_url' => 'http://source-b.test/movie.torrent', 'message' => 'timed out'],
    ]);
});

test('handleFailedAttempt records the failed candidate even when every candidate is exhausted', function () {
    Queue::fake();
    $job = makeTorrentJob([
        'status' => 'downloading',
        'source' => 'a',
        'torrent_url' => 'http://source-a.test/movie.torrent',
        'remaining_candidates' => [],
    ]);

    (new DownloadScheduler)->handleFailedAttempt($job, 'no peers');

    expect($job->fresh()->attempted_candidates)->toBe([
        ['source' => 'a', 'torrent_url' => 'http://source-a.test/movie.torrent', 'message' => 'no peers'],
    ]);
});

test('exhausting the last candidate promotes the oldest queued job into the freed slot', function () {
    Queue::fake();
    makeTorrentJob(['status' => 'pending']);
    makeTorrentJob(['status' => 'pending']);
    $failing = makeTorrentJob(['status' => 'downloading', 'remaining_candidates' => []]);
    $oldestQueued = makeTorrentJob(['status' => 'queued']);
    makeTorrentJob(['status' => 'queued']);

    (new DownloadScheduler)->handleFailedAttempt($failing, 'no peers');

    expect($oldestQueued->fresh()->status)->toBe('pending');
    Queue::assertPushed(StartTorrentDownload::class, 1);
});

test('handleCompleted promotes the oldest queued job when a slot is free', function () {
    Queue::fake();
    makeTorrentJob(['status' => 'pending']);
    makeTorrentJob(['status' => 'pending']);
    $oldestQueued = makeTorrentJob(['status' => 'queued']);
    $newerQueued = makeTorrentJob(['status' => 'queued']);

    (new DownloadScheduler)->handleCompleted();

    expect($oldestQueued->fresh()->status)->toBe('pending');
    expect($newerQueued->fresh()->status)->toBe('queued');
    Queue::assertPushed(StartTorrentDownload::class, 1);
});

test('handleCompleted does nothing when there is no queued job waiting', function () {
    Queue::fake();
    makeTorrentJob(['status' => 'pending']);

    (new DownloadScheduler)->handleCompleted();

    Queue::assertNotPushed(StartTorrentDownload::class);
});

test('promoteNext only promotes one job per call even when several slots are free', function () {
    Queue::fake();
    makeTorrentJob(['status' => 'pending']);
    $oldestQueued = makeTorrentJob(['status' => 'queued']);
    $newerQueued = makeTorrentJob(['status' => 'queued']);

    (new DownloadScheduler)->handleCompleted();

    expect($oldestQueued->fresh()->status)->toBe('pending');
    expect($newerQueued->fresh()->status)->toBe('queued');
    Queue::assertPushed(StartTorrentDownload::class, 1);
});

test('a pending ping job does not count against the download concurrency cap', function () {
    Queue::fake();
    // If the scheduler's active-job count ever dropped its `type` filter,
    // this pending ping job would make it look like 3 slots are taken
    // (the real cap), leaving no room to promote the queued download below.
    makeTorrentJob(['type' => 'ping', 'status' => 'pending']);
    makeTorrentJob(['status' => 'pending']);
    makeTorrentJob(['status' => 'pending']);
    $oldestQueuedPing = makeTorrentJob(['type' => 'ping', 'status' => 'queued']);
    $downloadQueued = makeTorrentJob(['status' => 'queued']);

    (new DownloadScheduler)->handleCompleted();

    expect($downloadQueued->fresh()->status)->toBe('pending');
    expect($oldestQueuedPing->fresh()->status)->toBe('queued');
    Queue::assertPushed(StartTorrentDownload::class, 1);
});

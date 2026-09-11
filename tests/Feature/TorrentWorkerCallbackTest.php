<?php

use App\Models\TorrentJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('a request without a worker token is rejected', function () {
    $job = TorrentJob::create(['job_id' => (string) Str::uuid(), 'type' => 'ping', 'status' => 'pending']);

    $this
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'completed',
        ])
        ->assertUnauthorized();
});

test('a request with an invalid worker token is rejected', function () {
    $job = TorrentJob::create(['job_id' => (string) Str::uuid(), 'type' => 'ping', 'status' => 'pending']);

    $this
        ->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'completed',
        ])
        ->assertUnauthorized();

    expect($job->fresh()->status)->toBe('pending');
});

test('a valid worker callback persists the reported status', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    $job = TorrentJob::create(['job_id' => (string) Str::uuid(), 'type' => 'ping', 'status' => 'pending']);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'completed',
            'message' => 'pong from python worker',
        ])
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect($job->fresh())
        ->status->toBe('completed')
        ->message->toBe('pong from python worker');
});

test('a callback for an unknown job id is rejected', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => (string) Str::uuid(),
            'status' => 'completed',
        ])
        ->assertUnprocessable();
});

test('a download progress callback persists bytes and completion state', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    $job = TorrentJob::create([
        'job_id' => (string) Str::uuid(),
        'type' => 'download',
        'status' => 'pending',
        'torrent_url' => 'http://torrent-fixture:6969/fixture.torrent',
        'total_bytes' => 1000,
    ]);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'downloading',
            'downloaded_bytes' => 400,
            'total_bytes' => 1000,
            'is_complete' => false,
        ])
        ->assertOk();

    expect($job->fresh())
        ->status->toBe('downloading')
        ->downloaded_bytes->toBe(400)
        ->is_complete->toBeFalse();

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'completed',
            'downloaded_bytes' => 1000,
            'total_bytes' => 1000,
            'is_complete' => true,
            'file_path' => '/shared/fixture.bin',
        ])
        ->assertOk();

    expect($job->fresh())
        ->status->toBe('completed')
        ->downloaded_bytes->toBe(1000)
        ->is_complete->toBeTrue()
        ->file_path->toBe('/shared/fixture.bin');
});

test('a failed callback with a remaining candidate falls back automatically instead of erroring', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    Queue::fake();
    $job = TorrentJob::create([
        'job_id' => (string) Str::uuid(),
        'type' => 'download',
        'status' => 'downloading',
        'torrent_url' => 'http://source-a.test/movie.torrent',
        'info_hash' => 'aaaa',
        'remaining_candidates' => [
            ['source' => 'b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent', 'info_hash' => 'bbbb'],
        ],
    ]);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'failed',
            'message' => 'download stalled: no progress and no peers available',
        ])
        ->assertOk();

    expect($job->fresh())
        ->status->toBe('pending')
        ->torrent_url->toBe('http://source-b.test/movie.torrent')
        ->info_hash->toBe('aaaa');
});

test('a failed callback with no remaining candidates terminally fails the job', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    $job = TorrentJob::create([
        'job_id' => (string) Str::uuid(),
        'type' => 'download',
        'status' => 'downloading',
        'torrent_url' => 'http://source-a.test/movie.torrent',
        'remaining_candidates' => [],
    ]);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $job->job_id,
            'status' => 'failed',
            'message' => 'no peers',
        ])
        ->assertOk();

    expect($job->fresh())
        ->status->toBe('failed')
        ->message->toBe('no peers');
});

test('a completed callback promotes the oldest queued download into the freed slot', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    Queue::fake();
    makeTorrentJob(['status' => 'pending']);
    makeTorrentJob(['status' => 'pending']);
    $completing = makeTorrentJob(['status' => 'downloading']);
    $queued = makeTorrentJob(['status' => 'queued']);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $completing->job_id,
            'status' => 'completed',
            'downloaded_bytes' => 100,
            'total_bytes' => 100,
            'is_complete' => true,
        ])
        ->assertOk();

    expect($queued->fresh()->status)->toBe('pending');
});

test('a late or duplicate callback for an already terminal job is ignored', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    Queue::fake();
    $completed = makeTorrentJob(['status' => 'completed', 'downloaded_bytes' => 100, 'total_bytes' => 100]);
    $queued = makeTorrentJob(['status' => 'queued']);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $completed->job_id,
            'status' => 'failed',
            'message' => 'stray late report',
        ])
        ->assertOk();

    expect($completed->fresh())
        ->status->toBe('completed')
        ->message->toBeNull();
    // A stray callback on an already-finished job must not free a phantom
    // concurrency slot: the queued job stays queued.
    expect($queued->fresh()->status)->toBe('queued');
});

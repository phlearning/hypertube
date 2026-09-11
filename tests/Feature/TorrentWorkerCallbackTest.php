<?php

use App\Models\TorrentJob;
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

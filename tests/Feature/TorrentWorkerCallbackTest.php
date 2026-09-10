<?php

use App\Models\WorkerPing;
use Illuminate\Support\Str;

test('a request without a worker token is rejected', function () {
    $ping = WorkerPing::create(['job_id' => (string) Str::uuid(), 'status' => 'pending']);

    $this
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $ping->job_id,
            'status' => 'completed',
        ])
        ->assertUnauthorized();
});

test('a request with an invalid worker token is rejected', function () {
    $ping = WorkerPing::create(['job_id' => (string) Str::uuid(), 'status' => 'pending']);

    $this
        ->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $ping->job_id,
            'status' => 'completed',
        ])
        ->assertUnauthorized();

    expect($ping->fresh()->status)->toBe('pending');
});

test('a valid worker callback persists the reported status', function () {
    config(['services.torrent_worker.secret' => 'test-secret']);
    $ping = WorkerPing::create(['job_id' => (string) Str::uuid(), 'status' => 'pending']);

    $this
        ->withHeader('Authorization', 'Bearer test-secret')
        ->postJson('/internal/torrent-worker/callback', [
            'job_id' => $ping->job_id,
            'status' => 'completed',
            'message' => 'pong from python worker',
        ])
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect($ping->fresh())
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

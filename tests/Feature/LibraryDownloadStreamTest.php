<?php

use App\Models\TorrentJob;
use App\Models\User;

function makeStreamableTorrentJob(string $content, int $downloadedBytes, array $overrides = []): TorrentJob
{
    $path = tempnam(sys_get_temp_dir(), 'stream_feature_test_');
    file_put_contents($path, $content);

    return makeTorrentJob(array_merge([
        'status' => 'downloading',
        'file_path' => $path,
        'downloaded_bytes' => $downloadedBytes,
        'total_bytes' => strlen($content),
    ], $overrides));
}

test('guests cannot stream a download', function () {
    $torrentJob = makeStreamableTorrentJob(str_repeat('a', 100), 100);

    $this
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertRedirect(route('login'));

    unlink($torrentJob->file_path);
});

test('a ping job cannot be streamed', function () {
    $user = User::factory()->create();
    $pingJob = makeTorrentJob(['type' => 'ping', 'status' => 'completed']);

    $this
        ->actingAs($user)
        ->get(route('library.downloads.stream', $pingJob))
        ->assertNotFound();
});

test('a download job with no file on disk yet cannot be streamed', function () {
    $user = User::factory()->create();
    $torrentJob = makeTorrentJob(['status' => 'downloading', 'file_path' => null]);

    $this
        ->actingAs($user)
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertNotFound();
});

test('without a Range header, it serves only the downloaded portion of the file', function () {
    $user = User::factory()->create();
    $content = str_repeat('a', 40).str_repeat('z', 60);
    $torrentJob = makeStreamableTorrentJob($content, downloadedBytes: 40);

    $response = $this
        ->actingAs($user)
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertOk();

    expect($response->headers->get('Content-Length'))->toBe('40')
        ->and($response->streamedContent())->toBe(str_repeat('a', 40));

    unlink($torrentJob->file_path);
});

test('a Range request within the downloaded portion is served as 206 Partial Content', function () {
    $user = User::factory()->create();
    $torrentJob = makeStreamableTorrentJob(str_repeat('a', 100), downloadedBytes: 40);

    $response = $this
        ->actingAs($user)
        ->withHeader('Range', 'bytes=0-9')
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertStatus(206);

    expect($response->headers->get('Content-Range'))->toBe('bytes 0-9/100')
        ->and($response->streamedContent())->toBe(str_repeat('a', 10));

    unlink($torrentJob->file_path);
});

test('a Range request starting beyond the downloaded portion is rejected with 416', function () {
    $user = User::factory()->create();
    $content = str_repeat('a', 40).str_repeat('z', 60);
    $torrentJob = makeStreamableTorrentJob($content, downloadedBytes: 40);

    $this
        ->actingAs($user)
        ->withHeader('Range', 'bytes=50-60')
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertStatus(416);

    unlink($torrentJob->file_path);
});

test('streaming a download records it as watched', function () {
    $user = User::factory()->create();
    $torrentJob = makeStreamableTorrentJob(str_repeat('a', 100), downloadedBytes: 40);
    expect($torrentJob->last_watched_at)->toBeNull();

    $this
        ->actingAs($user)
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertOk();

    expect($torrentJob->fresh()->last_watched_at)->not->toBeNull();

    unlink($torrentJob->file_path);
});

test('once transcoded, the endpoint serves playback_path in full, not the original file_path', function () {
    $user = User::factory()->create();
    // The original download: only partially "safe" per downloaded_bytes.
    $torrentJob = makeStreamableTorrentJob(str_repeat('a', 100), downloadedBytes: 40);

    $transcodedPath = tempnam(sys_get_temp_dir(), 'stream_feature_transcoded_');
    file_put_contents($transcodedPath, str_repeat('b', 55));
    $torrentJob->update(['playback_path' => $transcodedPath]);

    $response = $this
        ->actingAs($user)
        ->get(route('library.downloads.stream', $torrentJob))
        ->assertOk();

    expect($response->headers->get('Content-Length'))->toBe('55')
        ->and($response->streamedContent())->toBe(str_repeat('b', 55));

    unlink($torrentJob->file_path);
    unlink($transcodedPath);
});

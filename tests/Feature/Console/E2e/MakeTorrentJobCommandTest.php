<?php

use App\Models\TorrentJob;

test('it creates a torrent job with sane defaults and prints it as JSON', function () {
    $this->artisan('e2e:make-torrent-job', ['attributes' => '{}'])->assertSuccessful();

    $torrentJob = TorrentJob::sole();
    expect($torrentJob->type)->toBe('download')
        ->and($torrentJob->status)->toBe('queued');
});

test('given attributes override the defaults', function () {
    $this->artisan('e2e:make-torrent-job', [
        'attributes' => json_encode(['title' => 'Custom Title', 'status' => 'failed', 'downloaded_bytes' => 500]),
    ])->assertSuccessful();

    $torrentJob = TorrentJob::sole();
    expect($torrentJob->title)->toBe('Custom Title')
        ->and($torrentJob->status)->toBe('failed')
        ->and($torrentJob->downloaded_bytes)->toBe(500);
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('e2e:make-torrent-job', ['attributes' => '{}'])->assertFailed();
    expect(TorrentJob::count())->toBe(0);
});

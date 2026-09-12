<?php

use App\Models\TorrentJob;

test('it purges stale completed downloads and reports how many', function () {
    $filePath = tempnam(sys_get_temp_dir(), 'hypertube-source');
    $torrentJob = makeTorrentJob([
        'status' => 'completed',
        'file_path' => $filePath,
        'last_watched_at' => now()->subMonths(2),
    ]);
    makeTorrentJob(['status' => 'completed', 'file_path' => tempnam(sys_get_temp_dir(), 'hypertube-fresh'), 'last_watched_at' => now()]);

    $this->artisan('torrent:purge-stale-downloads')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect($torrentJob->fresh()->file_path)->toBeNull()
        ->and(file_exists($filePath))->toBeFalse()
        ->and(TorrentJob::count())->toBe(2);
});

test('it reports zero when nothing is stale', function () {
    $this->artisan('torrent:purge-stale-downloads')
        ->expectsOutputToContain('0')
        ->assertSuccessful();
});

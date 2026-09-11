<?php

use App\Events\TorrentJobProgressUpdated;
use Illuminate\Support\Facades\Event;

test('updating a progress-relevant field broadcasts the new state', function () {
    Event::fake([TorrentJobProgressUpdated::class]);
    $torrentJob = makeTorrentJob(['status' => 'pending']);

    $torrentJob->update(['downloaded_bytes' => 10]);

    Event::assertDispatched(
        TorrentJobProgressUpdated::class,
        fn (TorrentJobProgressUpdated $event) => $event->torrentJob->is($torrentJob)
    );
});

test('each progress-relevant field triggers a broadcast on its own', function (string $field, mixed $value) {
    Event::fake([TorrentJobProgressUpdated::class]);
    $torrentJob = makeTorrentJob();

    $torrentJob->update([$field => $value]);

    Event::assertDispatched(TorrentJobProgressUpdated::class);
})->with([
    ['status', 'downloading'],
    ['downloaded_bytes', 99],
    ['total_bytes', 500],
    ['is_complete', true],
    ['message', 'retrying'],
    ['source', 'public_domain_torrents'],
    ['file_path', '/shared/movie.mp4'],
    ['playback_path', '/shared/movie.transcoded.mp4'],
    ['transcode_status', 'processing'],
]);

test('updating an unrelated field does not broadcast', function () {
    Event::fake([TorrentJobProgressUpdated::class]);
    $torrentJob = makeTorrentJob();

    $torrentJob->update(['title' => 'A different title']);

    Event::assertNotDispatched(TorrentJobProgressUpdated::class);
});

test('saving without any actual change does not broadcast', function () {
    $torrentJob = makeTorrentJob(['status' => 'pending']);
    Event::fake([TorrentJobProgressUpdated::class]);

    $torrentJob->update(['status' => 'pending']);

    Event::assertNotDispatched(TorrentJobProgressUpdated::class);
});

test('creating a job does not broadcast', function () {
    Event::fake([TorrentJobProgressUpdated::class]);

    makeTorrentJob();

    Event::assertNotDispatched(TorrentJobProgressUpdated::class);
});

test('a broadcast failure is swallowed and never aborts the caller', function () {
    Event::listen(TorrentJobProgressUpdated::class, function () {
        throw new RuntimeException('Reverb unreachable');
    });
    $torrentJob = makeTorrentJob();

    $torrentJob->update(['downloaded_bytes' => 10]);

    expect($torrentJob->fresh()->downloaded_bytes)->toBe(10);
});

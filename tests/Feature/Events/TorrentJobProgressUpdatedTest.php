<?php

use App\Events\TorrentJobProgressUpdated;
use App\Services\Torrent\TorrentJobPresenter;
use Illuminate\Broadcasting\PrivateChannel;

test('it broadcasts on a private channel scoped to the job', function () {
    $torrentJob = makeTorrentJob(['status' => 'downloading']);

    $event = new TorrentJobProgressUpdated($torrentJob);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(PrivateChannel::class)
        ->and($channel->name)->toBe("private-torrent-job.{$torrentJob->id}");
});

test('it broadcasts under a stable event name', function () {
    $torrentJob = makeTorrentJob();

    expect((new TorrentJobProgressUpdated($torrentJob))->broadcastAs())->toBe('progress.updated');
});

test('its payload matches the TorrentJobPresenter output for that job', function () {
    $torrentJob = makeTorrentJob(['status' => 'downloading', 'downloaded_bytes' => 42, 'total_bytes' => 100]);

    $payload = (new TorrentJobProgressUpdated($torrentJob))->broadcastWith();

    expect($payload)->toBe(app(TorrentJobPresenter::class)->present($torrentJob));
});

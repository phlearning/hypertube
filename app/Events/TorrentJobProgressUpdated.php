<?php

namespace App\Events;

use App\Models\TorrentJob;
use App\Services\Torrent\TorrentJobPresenter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TorrentJobProgressUpdated implements ShouldBroadcastNow
{
    public function __construct(public readonly TorrentJob $torrentJob) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("torrent-job.{$this->torrentJob->id}");
    }

    public function broadcastAs(): string
    {
        return 'progress.updated';
    }

    /**
     * @return array<string, mixed> shape documented on TorrentJobPresenter::present(), the sole source of truth
     */
    public function broadcastWith(): array
    {
        return app(TorrentJobPresenter::class)->present($this->torrentJob);
    }
}

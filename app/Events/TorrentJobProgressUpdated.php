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
     * @return array{id: ?int, title: ?string, status: string, downloaded_bytes: int, total_bytes: ?int, is_complete: bool, message: ?string, source: ?string, seeders: ?int, peers: ?int, format: ?string, transcode_status: ?string, can_play: bool, attempted_candidates: array<int, array<string, mixed>>, media_info: ?array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return app(TorrentJobPresenter::class)->present($this->torrentJob);
    }
}

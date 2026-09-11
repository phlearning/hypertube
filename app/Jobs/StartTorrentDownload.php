<?php

namespace App\Jobs;

use App\Models\TorrentJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

class StartTorrentDownload implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly TorrentJob $torrentJob) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Redis::rpush('torrent:jobs', json_encode([
            'job_id' => $this->torrentJob->job_id,
            'type' => 'download',
            'torrent_url' => $this->torrentJob->torrent_url,
        ]));
    }
}

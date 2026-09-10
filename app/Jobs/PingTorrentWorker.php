<?php

namespace App\Jobs;

use App\Models\WorkerPing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PingTorrentWorker implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $jobId = (string) Str::uuid();

        WorkerPing::create([
            'job_id' => $jobId,
            'status' => 'pending',
        ]);

        Redis::rpush('torrent:jobs', json_encode([
            'job_id' => $jobId,
            'type' => 'ping',
        ]));
    }
}

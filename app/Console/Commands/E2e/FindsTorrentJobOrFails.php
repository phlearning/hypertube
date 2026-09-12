<?php

namespace App\Console\Commands\E2e;

use App\Models\TorrentJob;

/**
 * Shared by every e2e command that mutates an already-seeded job by job_id
 * (as opposed to e2e:make-torrent-job, which creates one) — both commands
 * need the exact same "found it, or fail cleanly" behavior.
 */
trait FindsTorrentJobOrFails
{
    private function findTorrentJobOrFail(string $jobId): ?TorrentJob
    {
        $torrentJob = TorrentJob::query()->where('job_id', $jobId)->first();

        if ($torrentJob === null) {
            $this->error("No torrent job found with job_id {$jobId}.");
        }

        return $torrentJob;
    }
}

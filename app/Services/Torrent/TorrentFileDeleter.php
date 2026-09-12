<?php

namespace App\Services\Torrent;

use App\Models\TorrentJob;
use Illuminate\Support\Facades\Log;

/**
 * Deleting a torrent job's files from disk is needed by two independent
 * features (admin bulk cleanup, monthly stale-download purge) that
 * otherwise share nothing — this is the one place that knows how.
 */
class TorrentFileDeleter
{
    public function delete(TorrentJob $torrentJob, string $context): void
    {
        foreach ([$torrentJob->file_path, $torrentJob->playback_path] as $path) {
            if ($path === null) {
                continue;
            }

            if (! @unlink($path) && file_exists($path)) {
                Log::warning("{$context}: failed to delete file.", [
                    'torrent_job_id' => $torrentJob->id,
                    'path' => $path,
                ]);
            }
        }
    }
}

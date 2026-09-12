<?php

namespace App\Services\Torrent;

use App\Models\TorrentJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class TorrentJobCleaner
{
    /**
     * An in-flight job that hasn't reported anything in this long is
     * considered abandoned (worker crashed, container restarted mid-download)
     * rather than genuinely still progressing.
     */
    private const STALE_AFTER_MINUTES = 30;

    /**
     * Delete torrent jobs matching $scope, optionally also deleting their
     * downloaded/transcoded files from disk. Returns the number of rows
     * deleted.
     */
    public function clear(string $scope, bool $deleteFiles): int
    {
        $query = match ($scope) {
            'all' => TorrentJob::query(),
            'stuck' => $this->stuckQuery(),
            default => throw new \InvalidArgumentException("Unknown clear scope: {$scope}"),
        };

        // Snapshot the matching IDs once and delete by ID from here on. The
        // 'stuck' scope's WHERE depends on status/updated_at, both of which
        // a live row can change between the file-deletion pass and the row
        // deletion — re-running that WHERE for the second pass could let a
        // row fall out of scope after its files were already unlinked,
        // leaving a DB row pointing at files that no longer exist.
        $ids = $query->pluck('id');

        if ($deleteFiles) {
            TorrentJob::query()->whereIn('id', $ids)->cursor()->each(function (TorrentJob $torrentJob): void {
                $this->deleteFiles($torrentJob);
            });
        }

        return TorrentJob::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @return Builder<TorrentJob>
     */
    private function stuckQuery(): Builder
    {
        return TorrentJob::query()->where(
            fn ($query) => $query->where('status', 'failed')
                ->orWhere(
                    fn ($query) => $query->whereIn('status', TorrentJob::ACTIVE_STATUSES)
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
                )
        );
    }

    private function deleteFiles(TorrentJob $torrentJob): void
    {
        foreach ([$torrentJob->file_path, $torrentJob->playback_path] as $path) {
            if ($path === null) {
                continue;
            }

            if (! @unlink($path) && file_exists($path)) {
                Log::warning('TorrentJobCleaner: failed to delete file.', [
                    'torrent_job_id' => $torrentJob->id,
                    'path' => $path,
                ]);
            }
        }
    }
}

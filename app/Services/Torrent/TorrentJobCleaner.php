<?php

namespace App\Services\Torrent;

use App\Enums\TorrentJobClearScope;
use App\Models\TorrentJob;
use Illuminate\Database\Eloquent\Builder;

class TorrentJobCleaner
{
    public function __construct(private readonly TorrentFileDeleter $fileDeleter) {}

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
    public function clear(TorrentJobClearScope $scope, bool $deleteFiles): int
    {
        $query = match ($scope) {
            TorrentJobClearScope::All => TorrentJob::query(),
            TorrentJobClearScope::Stuck => $this->stuckQuery(),
        };

        // Snapshot the matching IDs once and delete by ID from here on. The
        // 'stuck' scope's WHERE depends on status/updated_at, both of which
        // a live row can change between the file-deletion pass and the row
        // deletion — re-running that WHERE for the second pass could let a
        // row fall out of scope after its files were already unlinked,
        // leaving a DB row pointing at files that no longer exist.
        $ids = $query->pluck('id');

        if ($deleteFiles) {
            TorrentJob::query()->whereIn('id', $ids)->cursor()->each(
                fn (TorrentJob $torrentJob) => $this->fileDeleter->delete($torrentJob, self::class)
            );
        }

        return TorrentJob::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @return Builder<TorrentJob>
     */
    private function stuckQuery(): Builder
    {
        return TorrentJob::query()->where(
            fn (Builder $query) => $query->where('status', 'failed')
                ->orWhere(
                    fn (Builder $query) => $query->whereIn('status', TorrentJob::ACTIVE_STATUSES)
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
                )
        );
    }
}

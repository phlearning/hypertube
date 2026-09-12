<?php

namespace App\Services\Torrent;

use App\Models\TorrentJob;
use Illuminate\Database\Eloquent\Builder;

class StaleDownloadPurger
{
    /**
     * A completed download not watched in this long is purged: its file is
     * deleted from disk, but the row (title, seeders/peers, attempt
     * history, etc.) is kept — a purged movie is still a known movie, just
     * no longer cached, so a repeat request downloads it again instead of
     * finding nothing at all.
     */
    private const STALE_AFTER_MONTHS = 1;

    public function __construct(private readonly TorrentFileDeleter $fileDeleter) {}

    /**
     * Purge every stale completed download. Returns the number purged.
     */
    public function purge(): int
    {
        $staleJobs = $this->staleQuery()->get();

        foreach ($staleJobs as $torrentJob) {
            $this->fileDeleter->delete($torrentJob, self::class);

            $torrentJob->update([
                'file_path' => null,
                'playback_path' => null,
                'transcode_status' => null,
                'media_info' => null,
            ]);
        }

        return $staleJobs->count();
    }

    /**
     * @return Builder<TorrentJob>
     */
    private function staleQuery(): Builder
    {
        $cutoff = now()->subMonths(self::STALE_AFTER_MONTHS);

        return TorrentJob::query()
            ->where('type', 'download')
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->where(fn (Builder $query) => $query
                ->where('last_watched_at', '<', $cutoff)
                ->orWhere(fn (Builder $query) => $query->whereNull('last_watched_at')->where('updated_at', '<', $cutoff))
            );
    }
}

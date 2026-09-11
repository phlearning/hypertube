<?php

namespace App\Services\Torrent;

use App\Jobs\StartTorrentDownload;
use App\Models\TorrentJob;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DownloadScheduler
{
    private const MAX_CONCURRENT_DOWNLOADS = 3;

    /**
     * Start the job now if a concurrency slot is free, otherwise leave it
     * queued — promoteNext() will pick it up once a slot frees.
     */
    public function admit(TorrentJob $job): void
    {
        $this->withSchedulerLock(function () use ($job) {
            if ($this->activeCount() < self::MAX_CONCURRENT_DOWNLOADS) {
                $this->start($job);
            }
        });
    }

    /**
     * A download attempt failed (dead candidate, timeout, mid-download
     * crash). Fall back to the next candidate in the job's chain without any
     * user-facing error, or mark the job failed once the chain is exhausted.
     *
     * @return bool true if another candidate is now being attempted, false if the job is terminally failed.
     */
    public function handleFailedAttempt(TorrentJob $job, ?string $message): bool
    {
        $remaining = $job->remaining_candidates ?? [];

        if ($remaining === []) {
            $job->update(['status' => 'failed', 'message' => $message]);
            $this->promoteNext();

            return false;
        }

        $next = array_shift($remaining);

        // info_hash is deliberately left untouched here: it's the identity
        // LibraryDownloadController dedups new requests against, and it must
        // stay stable across fallbacks — updating it to the new candidate's
        // hash would let a second request for the same movie stop finding
        // this still-active job and start a redundant duplicate download.
        //
        // file_path/downloaded_bytes/total_bytes/is_complete DO get cleared:
        // they describe the failed candidate's file, and the streaming
        // endpoint trusts file_path as "safe to read right now" — leaving it
        // set would let a request in the window between this fallback and
        // the next candidate's first progress report stream the wrong file.
        $job->update([
            'torrent_url' => $next['torrent_url'],
            'source' => $next['source'],
            'remaining_candidates' => $remaining,
            'message' => $message,
            'file_path' => null,
            'downloaded_bytes' => 0,
            'total_bytes' => null,
            'is_complete' => false,
        ]);

        $this->start($job);

        return true;
    }

    /**
     * A download finished successfully, freeing a concurrency slot.
     */
    public function handleCompleted(): void
    {
        $this->promoteNext();
    }

    private function start(TorrentJob $job): void
    {
        $job->update(['status' => 'pending']);

        StartTorrentDownload::dispatch($job);
    }

    private function promoteNext(): void
    {
        $this->withSchedulerLock(function () {
            if ($this->activeCount() >= self::MAX_CONCURRENT_DOWNLOADS) {
                return;
            }

            $next = TorrentJob::query()
                ->where('type', 'download')
                ->where('status', 'queued')
                ->oldest('id')
                ->first();

            if ($next !== null) {
                $this->start($next);
            }
        });
    }

    private function activeCount(): int
    {
        return TorrentJob::query()
            ->where('type', 'download')
            ->whereIn('status', TorrentJob::SLOT_OCCUPYING_STATUSES)
            ->count();
    }

    /**
     * Best-effort: if the scheduler lock can't be acquired in time, skip this
     * admission/promotion decision rather than fail the caller's request (a
     * worker callback, in promoteNext()'s case). The next completion or
     * failure elsewhere will retry it, so a queued job is never stuck forever
     * over one contended lock.
     */
    private function withSchedulerLock(Closure $callback): void
    {
        try {
            Cache::lock('torrent-download-scheduler', 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            Log::warning('Torrent download scheduler lock timed out; skipping this admission/promotion cycle.');
        }
    }
}

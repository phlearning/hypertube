<?php

namespace App\Models;

use App\Events\TorrentJobProgressUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class TorrentJob extends Model
{
    /**
     * Statuses that occupy one of the concurrency-cap slots.
     */
    public const SLOT_OCCUPYING_STATUSES = ['pending', 'downloading'];

    /**
     * Every status that represents an in-flight (not yet terminal) download,
     * including one still waiting in the FIFO queue for a free slot. Used to
     * find the job already handling a given movie for deduplication.
     */
    public const ACTIVE_STATUSES = ['queued', 'pending', 'downloading'];

    /**
     * Fields the download page's progress broadcast cares about. Kept as a
     * model-level hook rather than a per-call-site broadcast so every writer
     * (worker callback, scheduler, transcoding job) stays automatically in
     * sync without having to remember to broadcast itself.
     *
     * Only fires for `save()`/`update()` on a loaded instance — a bulk
     * `TorrentJob::where(...)->update(...)` would skip this silently, since
     * Eloquent never hydrates individual models (and so never fires their
     * `updated` event) for query-builder bulk updates. No current call site
     * does this; keep it that way, or broadcast explicitly if one ever needs to.
     */
    private const BROADCAST_RELEVANT_FIELDS = [
        'status',
        'downloaded_bytes',
        'total_bytes',
        'is_complete',
        'message',
        'source',
        'file_path',
        'playback_path',
        'transcode_status',
    ];

    protected static function booted(): void
    {
        static::updated(function (TorrentJob $torrentJob): void {
            if (! $torrentJob->wasChanged(self::BROADCAST_RELEVANT_FIELDS)) {
                return;
            }

            // Broadcasting is best-effort: a Reverb outage must never abort
            // whatever the caller's ->update() was itself a step of (e.g.
            // freeing a concurrency slot, dispatching the next job) — the
            // write to the database has already happened by this point
            // regardless of what happens here.
            try {
                event(new TorrentJobProgressUpdated($torrentJob));
            } catch (Throwable $exception) {
                Log::warning('Failed to broadcast torrent job progress.', [
                    'torrent_job_id' => $torrentJob->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }

    protected $fillable = [
        'job_id',
        'type',
        'title',
        'status',
        'message',
        'torrent_url',
        'source',
        'info_hash',
        'remaining_candidates',
        'downloaded_bytes',
        'total_bytes',
        'is_complete',
        'file_path',
        'playback_path',
        'transcode_status',
    ];

    protected $casts = [
        'downloaded_bytes' => 'integer',
        'total_bytes' => 'integer',
        'is_complete' => 'boolean',
        'remaining_candidates' => 'array',
    ];
}

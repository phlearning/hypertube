<?php

namespace App\Models;

use App\Events\TorrentJobProgressUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
     * Fires automatically for `save()`/`update()` on a loaded instance
     * (below). A plain `TorrentJob::where(...)->update(...)` bulk query does
     * NOT fire this — Eloquent never hydrates individual models for a
     * query-builder update — use `updateManyAndBroadcast()` for a bulk write
     * that should still notify watchers.
     */
    public const BROADCAST_RELEVANT_FIELDS = [
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

    /**
     * Of the fields above, these two can change on nearly every worker
     * callback while a download is active — throttled below so a fast
     * download doesn't broadcast several times a second. Every other
     * relevant field changes rarely (a status/message/path transition) and
     * always broadcasts immediately.
     */
    private const THROTTLED_FIELDS = ['downloaded_bytes', 'total_bytes'];

    private const THROTTLE_SECONDS = 1;

    /**
     * How often a still-playing video bumps last_watched_at. Streaming
     * issues a request per Range chunk (dozens per playback session), so
     * this is throttled the same way byte-progress broadcasts are — the
     * purge job only cares about the date, not the second.
     */
    private const WATCHED_THROTTLE_SECONDS = 3600;

    protected static function booted(): void
    {
        static::updated(function (TorrentJob $torrentJob): void {
            self::broadcastProgressIfRelevant($torrentJob);
        });
    }

    /**
     * Broadcast $torrentJob's current state if the fields that just changed
     * (as tracked by the model itself since its last sync) are
     * broadcast-relevant, respecting the byte-progress throttle. Used by the
     * instance-update hook above.
     */
    public static function broadcastProgressIfRelevant(TorrentJob $torrentJob): void
    {
        if ($torrentJob->wasChanged(self::BROADCAST_RELEVANT_FIELDS)) {
            self::broadcastProgress($torrentJob, array_keys($torrentJob->getChanges()));
        }
    }

    /**
     * Bulk-update several jobs at once while still broadcasting to each —
     * plain `TorrentJob::where(...)->update(...)` bypasses model events (and
     * so this feature's progress broadcast) entirely, since Eloquent never
     * hydrates individual models for a query-builder update. Route any bulk
     * write that should still notify watchers through here instead.
     *
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $attributes
     */
    public static function updateManyAndBroadcast(array $ids, array $attributes): int
    {
        $affected = static::query()->whereIn('id', $ids)->update($attributes);

        if ($affected > 0) {
            $changedFields = array_keys($attributes);

            static::query()->whereIn('id', $ids)->get()->each(
                fn (TorrentJob $torrentJob) => self::broadcastProgress($torrentJob, $changedFields)
            );
        }

        return $affected;
    }

    /**
     * Broadcast $torrentJob's current state if $changedFields intersects the
     * broadcast-relevant fields, respecting the byte-progress throttle.
     *
     * @param  array<int, string>  $changedFields
     */
    public static function broadcastProgress(TorrentJob $torrentJob, array $changedFields): void
    {
        $relevant = array_intersect($changedFields, self::BROADCAST_RELEVANT_FIELDS);

        if ($relevant === []) {
            return;
        }

        $onlyThrottledFieldsChanged = array_diff($relevant, self::THROTTLED_FIELDS) === [];

        if ($onlyThrottledFieldsChanged && self::isThrottled($torrentJob)) {
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
    }

    /**
     * Cache::add both checks and (atomically) claims the throttle window in
     * one step: it returns true — "not throttled" — only the first time
     * it's called for a given job within THROTTLE_SECONDS.
     */
    private static function isThrottled(TorrentJob $torrentJob): bool
    {
        $key = "torrent-job-broadcast-throttle:{$torrentJob->job_id}";

        return ! Cache::add($key, true, self::THROTTLE_SECONDS);
    }

    /**
     * Record that this job's file was just streamed to a viewer, throttled
     * so a single playback session's many Range requests only write once.
     * `last_watched_at` deliberately isn't a broadcast-relevant field (see
     * BROADCAST_RELEVANT_FIELDS above) — watch history isn't progress.
     */
    public function markWatched(): void
    {
        $key = "torrent-job-watched-throttle:{$this->job_id}";

        if (! Cache::add($key, true, self::WATCHED_THROTTLE_SECONDS)) {
            return;
        }

        $this->update(['last_watched_at' => now()]);
    }

    protected $fillable = [
        'job_id',
        'type',
        'title',
        'status',
        'message',
        'torrent_url',
        'source',
        'seeders',
        'peers',
        'info_hash',
        'remaining_candidates',
        'attempted_candidates',
        'downloaded_bytes',
        'total_bytes',
        'is_complete',
        'file_path',
        'playback_path',
        'transcode_status',
        'media_info',
        'last_watched_at',
    ];

    protected $casts = [
        'downloaded_bytes' => 'integer',
        'total_bytes' => 'integer',
        'seeders' => 'integer',
        'peers' => 'integer',
        'is_complete' => 'boolean',
        'remaining_candidates' => 'array',
        'attempted_candidates' => 'array',
        'media_info' => 'array',
        'last_watched_at' => 'datetime',
    ];
}

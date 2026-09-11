<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $fillable = [
        'job_id',
        'type',
        'title',
        'status',
        'message',
        'torrent_url',
        'info_hash',
        'remaining_candidates',
        'downloaded_bytes',
        'total_bytes',
        'is_complete',
        'file_path',
    ];

    protected $casts = [
        'downloaded_bytes' => 'integer',
        'total_bytes' => 'integer',
        'is_complete' => 'boolean',
        'remaining_candidates' => 'array',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TorrentJob extends Model
{
    protected $fillable = [
        'job_id',
        'type',
        'title',
        'status',
        'message',
        'torrent_url',
        'downloaded_bytes',
        'total_bytes',
        'is_complete',
        'file_path',
    ];

    protected $casts = [
        'downloaded_bytes' => 'integer',
        'total_bytes' => 'integer',
        'is_complete' => 'boolean',
    ];
}

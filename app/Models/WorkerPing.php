<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerPing extends Model
{
    protected $fillable = [
        'job_id',
        'status',
        'message',
    ];
}

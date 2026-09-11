<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\TorrentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TorrentWorkerCallbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'uuid', 'exists:torrent_jobs,job_id'],
            'status' => ['required', 'in:pending,downloading,completed,failed'],
            'message' => ['nullable', 'string', 'max:1000'],
            'downloaded_bytes' => ['nullable', 'integer', 'min:0'],
            'total_bytes' => ['nullable', 'integer', 'min:0'],
            'is_complete' => ['nullable', 'boolean'],
            'file_path' => ['nullable', 'string', 'max:2048'],
        ]);

        // 'message' always overwrites (including clearing it to null), matching
        // every callback's intent to state the job's full current status. The
        // download-progress fields are only ever sent by download jobs, so they
        // update only when present rather than blanking a ping job's row.
        $updates = [
            'status' => $validated['status'],
            'message' => $validated['message'] ?? null,
        ];

        foreach (['downloaded_bytes', 'total_bytes', 'is_complete', 'file_path'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        TorrentJob::where('job_id', $validated['job_id'])->update($updates);

        return response()->json(['status' => 'ok']);
    }
}

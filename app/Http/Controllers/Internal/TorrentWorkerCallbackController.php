<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\TorrentJob;
use App\Services\Torrent\DownloadScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TorrentWorkerCallbackController extends Controller
{
    public function __construct(private readonly DownloadScheduler $scheduler) {}

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

        $torrentJob = TorrentJob::where('job_id', $validated['job_id'])->firstOrFail();

        // A late or duplicate callback (client-side timeout on an otherwise
        // successful report, a retried request) must not re-run side effects
        // like falling back to another candidate or freeing a concurrency
        // slot a second time on a job that has already reached a terminal
        // state — silently accept it without reprocessing.
        if (in_array($torrentJob->status, ['completed', 'failed'], true)) {
            return response()->json(['status' => 'ok']);
        }

        // A failed download attempt falls back to the next candidate in the
        // job's own chain (or terminally fails once it's exhausted) instead
        // of being written through as a plain status update.
        if ($torrentJob->type === 'download' && $validated['status'] === 'failed') {
            $this->scheduler->handleFailedAttempt($torrentJob, $validated['message'] ?? null);

            return response()->json(['status' => 'ok']);
        }

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

        $torrentJob->update($updates);

        if ($torrentJob->type === 'download' && $validated['status'] === 'completed') {
            $this->scheduler->handleCompleted();
        }

        return response()->json(['status' => 'ok']);
    }
}

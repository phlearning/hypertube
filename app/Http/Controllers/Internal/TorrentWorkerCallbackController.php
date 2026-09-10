<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\WorkerPing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TorrentWorkerCallbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'uuid', 'exists:worker_pings,job_id'],
            'status' => ['required', 'in:completed,failed'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        WorkerPing::where('job_id', $validated['job_id'])->update([
            'status' => $validated['status'],
            'message' => $validated['message'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }
}

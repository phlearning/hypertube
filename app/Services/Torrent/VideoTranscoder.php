<?php

namespace App\Services\Torrent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class VideoTranscoder
{
    /**
     * webm has no faststart-style "metadata at the end" problem the way mp4
     * does, and browsers already play it natively — nothing to do.
     */
    private const NO_PROCESSING_NEEDED = ['webm'];

    /**
     * Decide what a file at $path needs before it's safe/pleasant to stream:
     * 'skip' (already fine as-is), 'remux' (native format, but move its
     * metadata to the front — fast, no re-encoding), or 'transcode' (not a
     * format browsers play natively at all — full re-encode to mp4/h264/aac).
     */
    public function planFor(string $path): string
    {
        $extension = $this->extension($path);

        if (in_array($extension, self::NO_PROCESSING_NEEDED, true)) {
            return 'skip';
        }

        if ($extension === 'mp4') {
            return 'remux';
        }

        return 'transcode';
    }

    public function outputPath(string $sourcePath): string
    {
        $directory = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);

        return $directory.'/'.$filename.'.transcoded.mp4';
    }

    /**
     * Run ffmpeg according to planFor($sourcePath). Returns false without
     * doing any work for a 'skip' plan — the caller decides what that means
     * (it isn't a failure).
     */
    public function transcode(string $sourcePath, string $destinationPath): bool
    {
        $plan = $this->planFor($sourcePath);

        if ($plan === 'skip') {
            return false;
        }

        $arguments = $plan === 'remux'
            ? ['ffmpeg', '-y', '-i', $sourcePath, '-c', 'copy', '-movflags', '+faststart', $destinationPath]
            : ['ffmpeg', '-y', '-i', $sourcePath, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-c:a', 'aac', '-movflags', '+faststart', $destinationPath];

        // No timeout: a full re-encode of a large file can legitimately take
        // minutes, and this always runs inside the queue worker, never on a
        // request thread.
        $result = Process::timeout(0)->run($arguments);

        if (! $result->successful()) {
            Log::warning('VideoTranscoder: ffmpeg failed.', [
                'source' => $sourcePath,
                'plan' => $plan,
                'exit_code' => $result->exitCode(),
                'error_output' => $result->errorOutput(),
            ]);
        }

        return $result->successful();
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}

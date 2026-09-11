<?php

namespace App\Jobs;

use App\Models\TorrentJob;
use App\Services\Torrent\VideoTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranscodeVideo implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly TorrentJob $torrentJob)
    {
        // A full re-encode can run for minutes of CPU-bound ffmpeg work; on
        // the default queue that would sit in front of (and delay) other
        // users' StartTorrentDownload dispatches, since queue:work processes
        // one job at a time. A dedicated queue/worker keeps the two apart.
        $this->onQueue('transcoding');
    }

    /**
     * Execute the job.
     */
    public function handle(VideoTranscoder $transcoder): void
    {
        $sourcePath = $this->torrentJob->file_path;

        if ($sourcePath === null) {
            return;
        }

        if ($transcoder->planFor($sourcePath) === 'skip') {
            $this->torrentJob->update(['transcode_status' => 'skipped']);

            return;
        }

        $this->torrentJob->update(['transcode_status' => 'processing']);

        // Any failure here — including ffmpeg itself being missing or some
        // other unexpected exception, not just a non-zero exit code — must
        // still land on a terminal transcode_status. Leaving it stuck at
        // 'processing' would poll the download page forever, since that's
        // not one of the terminal states it waits for.
        $destinationPath = $transcoder->outputPath($sourcePath);

        try {
            $succeeded = $transcoder->transcode($sourcePath, $destinationPath);
        } catch (Throwable) {
            $succeeded = false;
        }

        $this->torrentJob->update($succeeded
            ? ['transcode_status' => 'completed', 'playback_path' => $destinationPath]
            : ['transcode_status' => 'failed']);
    }
}

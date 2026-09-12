<?php

namespace App\Services\Torrent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JsonException;

class MediaProbe
{
    /**
     * Inspect a video file with ffprobe. Returns null if the file can't be
     * probed at all, or has no video stream — informational data only, so a
     * failure here is never treated as an error by callers.
     *
     * @return array{width: ?int, height: ?int, duration_seconds: ?float, video_codec: ?string, audio_codec: ?string}|null
     */
    public function probe(string $path): ?array
    {
        $result = Process::timeout(30)->run([
            'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        if (! $result->successful()) {
            Log::warning('MediaProbe: ffprobe failed.', [
                'path' => $path,
                'exit_code' => $result->exitCode(),
                'error_output' => $result->errorOutput(),
            ]);

            return null;
        }

        try {
            /** @var array{streams?: array<int, array<string, mixed>>, format?: array<string, mixed>} $data */
            $data = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $streams = $data['streams'] ?? [];
        $video = self::firstStreamOfType($streams, 'video');

        if ($video === null) {
            return null;
        }

        $audio = self::firstStreamOfType($streams, 'audio');
        $duration = $data['format']['duration'] ?? null;

        return [
            'width' => isset($video['width']) ? (int) $video['width'] : null,
            'height' => isset($video['height']) ? (int) $video['height'] : null,
            'duration_seconds' => $duration !== null ? (float) $duration : null,
            'video_codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    private static function firstStreamOfType(array $streams, string $codecType): ?array
    {
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) === $codecType) {
                return $stream;
            }
        }

        return null;
    }
}

<?php

use App\Services\Torrent\MediaProbe;
use Illuminate\Support\Facades\Process;

function ffprobeJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ],
        'format' => ['duration' => '125.400000'],
    ], $overrides));
}

test('probe() extracts resolution, duration and codecs from ffprobe output', function () {
    Process::fake(['*' => Process::result(output: ffprobeJson())]);

    $info = (new MediaProbe)->probe('/shared/movie.mp4');

    expect($info)->toBe([
        'width' => 1920,
        'height' => 1080,
        'duration_seconds' => 125.4,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ]);
});

test('probe() runs ffprobe with the expected arguments', function () {
    Process::fake(['*' => Process::result(output: ffprobeJson())]);

    (new MediaProbe)->probe('/shared/movie.mp4');

    Process::assertRan(function ($process) {
        return $process->command === [
            'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', '/shared/movie.mp4',
        ];
    });
});

test('probe() returns null when there is no video stream', function () {
    Process::fake(['*' => Process::result(output: ffprobeJson(['streams' => [
        ['codec_type' => 'audio', 'codec_name' => 'mp3'],
    ]]))]);

    expect((new MediaProbe)->probe('/shared/audio-only.mp4'))->toBeNull();
});

test('probe() returns null when ffprobe fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'No such file')]);

    expect((new MediaProbe)->probe('/shared/missing.mp4'))->toBeNull();
});

test('probe() returns null when ffprobe outputs invalid json', function () {
    Process::fake(['*' => Process::result(output: 'not json')]);

    expect((new MediaProbe)->probe('/shared/movie.mp4'))->toBeNull();
});

test('probe() tolerates a missing audio stream', function () {
    Process::fake(['*' => Process::result(output: ffprobeJson(['streams' => [
        ['codec_type' => 'video', 'codec_name' => 'vp9', 'width' => 1280, 'height' => 720],
    ]]))]);

    expect((new MediaProbe)->probe('/shared/silent.webm'))->toBe([
        'width' => 1280,
        'height' => 720,
        'duration_seconds' => 125.4,
        'video_codec' => 'vp9',
        'audio_codec' => null,
    ]);
});

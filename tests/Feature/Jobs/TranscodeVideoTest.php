<?php

use App\Jobs\TranscodeVideo;
use App\Services\Torrent\MediaProbe;
use App\Services\Torrent\VideoTranscoder;
use Illuminate\Support\Facades\Process;

function fakeFfprobeJson(): string
{
    return json_encode([
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ],
        'format' => ['duration' => '42.5'],
    ]);
}

test('a native webm file is marked skipped and probed without running ffmpeg', function () {
    Process::fake(["'ffprobe'*" => Process::result(output: fakeFfprobeJson())]);
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.webm']);

    (new TranscodeVideo($job))->handle(new VideoTranscoder, new MediaProbe);

    Process::assertNotRan(fn ($process) => $process->command[0] === 'ffmpeg');
    expect($job->fresh())
        ->transcode_status->toBe('skipped')
        ->playback_path->toBeNull()
        ->media_info->toBe([
            'width' => 1280,
            'height' => 720,
            'duration_seconds' => 42.5,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
        ]);
});

test('a successful transcode records the output as playback_path and probes it', function () {
    Process::fake([
        "'ffmpeg'*" => Process::result(exitCode: 0),
        "'ffprobe'*" => Process::result(output: fakeFfprobeJson()),
    ]);
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    (new TranscodeVideo($job))->handle(new VideoTranscoder, new MediaProbe);

    expect($job->fresh())
        ->transcode_status->toBe('completed')
        ->playback_path->toBe('/shared/movie.transcoded.mp4')
        ->media_info->toBe([
            'width' => 1280,
            'height' => 720,
            'duration_seconds' => 42.5,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
        ]);
    Process::assertRan(fn ($process) => $process->command === [
        'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', '/shared/movie.transcoded.mp4',
    ]);
});

test('a failed ffmpeg run is recorded without setting playback_path or media_info', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    (new TranscodeVideo($job))->handle(new VideoTranscoder, new MediaProbe);

    expect($job->fresh())
        ->transcode_status->toBe('failed')
        ->playback_path->toBeNull()
        ->media_info->toBeNull();
});

test('an unexpected exception during transcoding still lands on a terminal failed status', function () {
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    $transcoder = Mockery::mock(VideoTranscoder::class);
    $transcoder->shouldReceive('planFor')->andReturn('transcode');
    $transcoder->shouldReceive('outputPath')->andReturn('/shared/movie.transcoded.mp4');
    $transcoder->shouldReceive('transcode')->andThrow(new RuntimeException('ffmpeg binary not found'));

    (new TranscodeVideo($job))->handle($transcoder, new MediaProbe);

    expect($job->fresh())
        ->transcode_status->toBe('failed')
        ->playback_path->toBeNull();
});

test('a probe failure never turns a successful transcode into a failure', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    $prober = Mockery::mock(MediaProbe::class);
    $prober->shouldReceive('probe')->andThrow(new RuntimeException('ffprobe binary not found'));

    (new TranscodeVideo($job))->handle(new VideoTranscoder, $prober);

    expect($job->fresh())
        ->transcode_status->toBe('completed')
        ->playback_path->toBe('/shared/movie.transcoded.mp4')
        ->media_info->toBeNull();
});

test('a job with no file_path yet is a no-op', function () {
    Process::fake();
    $job = makeTorrentJob(['status' => 'downloading', 'file_path' => null]);

    (new TranscodeVideo($job))->handle(new VideoTranscoder, new MediaProbe);

    Process::assertNothingRan();
    expect($job->fresh()->transcode_status)->toBeNull();
});

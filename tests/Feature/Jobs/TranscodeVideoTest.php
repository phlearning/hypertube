<?php

use App\Jobs\TranscodeVideo;
use App\Services\Torrent\VideoTranscoder;
use Illuminate\Support\Facades\Process;

test('a native webm file is marked skipped without running ffmpeg', function () {
    Process::fake();
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.webm']);

    (new TranscodeVideo($job))->handle(new VideoTranscoder);

    Process::assertNothingRan();
    expect($job->fresh())
        ->transcode_status->toBe('skipped')
        ->playback_path->toBeNull();
});

test('a successful transcode records the output as playback_path', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    (new TranscodeVideo($job))->handle(new VideoTranscoder);

    expect($job->fresh())
        ->transcode_status->toBe('completed')
        ->playback_path->toBe('/shared/movie.transcoded.mp4');
});

test('a failed ffmpeg run is recorded without setting playback_path', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    (new TranscodeVideo($job))->handle(new VideoTranscoder);

    expect($job->fresh())
        ->transcode_status->toBe('failed')
        ->playback_path->toBeNull();
});

test('an unexpected exception during transcoding still lands on a terminal failed status', function () {
    $job = makeTorrentJob(['status' => 'completed', 'file_path' => '/shared/movie.mkv']);

    $transcoder = Mockery::mock(VideoTranscoder::class);
    $transcoder->shouldReceive('planFor')->andReturn('transcode');
    $transcoder->shouldReceive('outputPath')->andReturn('/shared/movie.transcoded.mp4');
    $transcoder->shouldReceive('transcode')->andThrow(new RuntimeException('ffmpeg binary not found'));

    (new TranscodeVideo($job))->handle($transcoder);

    expect($job->fresh())
        ->transcode_status->toBe('failed')
        ->playback_path->toBeNull();
});

test('a job with no file_path yet is a no-op', function () {
    Process::fake();
    $job = makeTorrentJob(['status' => 'downloading', 'file_path' => null]);

    (new TranscodeVideo($job))->handle(new VideoTranscoder);

    Process::assertNothingRan();
    expect($job->fresh()->transcode_status)->toBeNull();
});

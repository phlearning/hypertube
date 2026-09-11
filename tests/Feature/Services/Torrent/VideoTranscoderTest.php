<?php

use App\Services\Torrent\VideoTranscoder;
use Illuminate\Support\Facades\Process;

test('transcode() does nothing and returns false for a skip plan', function () {
    Process::fake();

    $result = (new VideoTranscoder)->transcode('/shared/movie.webm', '/shared/movie.transcoded.mp4');

    expect($result)->toBeFalse();
    Process::assertNothingRan();
});

test('transcode() runs a stream-copy remux for a native mp4, not a re-encode', function () {
    Process::fake();

    (new VideoTranscoder)->transcode('/shared/movie.mp4', '/shared/movie.transcoded.mp4');

    Process::assertRan(function ($process) {
        $command = $process->command;

        return in_array('ffmpeg', $command, true)
            && in_array('-c', $command, true)
            && in_array('copy', $command, true)
            && in_array('+faststart', $command, true)
            && ! in_array('libx264', $command, true);
    });
});

test('transcode() runs a full h264/aac re-encode for a non-native format', function () {
    Process::fake();

    (new VideoTranscoder)->transcode('/shared/movie.mkv', '/shared/movie.transcoded.mp4');

    Process::assertRan(function ($process) {
        $command = $process->command;

        return in_array('ffmpeg', $command, true)
            && in_array('libx264', $command, true)
            && in_array('aac', $command, true)
            && in_array('+faststart', $command, true);
    });
});

test('transcode() returns true when ffmpeg succeeds and false when it fails', function () {
    Process::fake([
        '*' => Process::result(exitCode: 0),
    ]);
    expect((new VideoTranscoder)->transcode('/shared/movie.mkv', '/shared/out.mp4'))->toBeTrue();

    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'ffmpeg: unsupported codec'),
    ]);
    expect((new VideoTranscoder)->transcode('/shared/movie.mkv', '/shared/out.mp4'))->toBeFalse();
});

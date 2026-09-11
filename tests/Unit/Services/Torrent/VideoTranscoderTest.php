<?php

use App\Services\Torrent\VideoTranscoder;

test('a webm file needs no processing at all', function () {
    expect((new VideoTranscoder)->planFor('/shared/movie.webm'))->toBe('skip');
});

test('an mp4 file only needs a fast faststart remux, not a full re-encode', function () {
    expect((new VideoTranscoder)->planFor('/shared/movie.mp4'))->toBe('remux');
});

test('non-native formats need a full transcode to mp4', function () {
    expect((new VideoTranscoder)->planFor('/shared/movie.mkv'))->toBe('transcode')
        ->and((new VideoTranscoder)->planFor('/shared/movie.avi'))->toBe('transcode')
        ->and((new VideoTranscoder)->planFor('/shared/movie.mpeg'))->toBe('transcode')
        ->and((new VideoTranscoder)->planFor('/shared/movie.ogv'))->toBe('transcode')
        ->and((new VideoTranscoder)->planFor('/shared/movie.mov'))->toBe('transcode');
});

test('plan matching is case-insensitive', function () {
    expect((new VideoTranscoder)->planFor('/shared/movie.MP4'))->toBe('remux')
        ->and((new VideoTranscoder)->planFor('/shared/movie.MKV'))->toBe('transcode');
});

test('the output path sits next to the source with a .transcoded.mp4 suffix', function () {
    expect((new VideoTranscoder)->outputPath('/shared/AboutBan1935/AboutBan1935_edit.mkv'))
        ->toBe('/shared/AboutBan1935/AboutBan1935_edit.transcoded.mp4');
});

test('the output path is stable for a file that already has no extension', function () {
    expect((new VideoTranscoder)->outputPath('/shared/fixture'))
        ->toBe('/shared/fixture.transcoded.mp4');
});

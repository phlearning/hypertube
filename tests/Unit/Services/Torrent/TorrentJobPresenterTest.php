<?php

use App\Models\TorrentJob;
use App\Services\Torrent\TorrentJobPresenter;
use App\Services\Torrent\VideoTranscoder;
use Illuminate\Support\Str;

function makePresenter(): TorrentJobPresenter
{
    return new TorrentJobPresenter(new VideoTranscoder);
}

test('present() exposes the fields the download page needs', function () {
    $torrentJob = new TorrentJob([
        'job_id' => (string) Str::uuid(),
        'type' => 'download',
        'title' => 'Movie',
        'status' => 'downloading',
        'downloaded_bytes' => 42,
        'total_bytes' => 100,
        'is_complete' => false,
        'message' => 'En cours',
        'source' => 'archive_org',
        'seeders' => 12,
        'peers' => 30,
        'file_path' => '/shared/Movie/Movie.mkv',
        'transcode_status' => 'processing',
        'attempted_candidates' => [['source' => 'a', 'torrent_url' => 'http://a.test', 'message' => 'timed out']],
        'media_info' => ['width' => 1920, 'height' => 1080, 'duration_seconds' => 120.5, 'video_codec' => 'h264', 'audio_codec' => 'aac'],
    ]);
    $torrentJob->id = 7;

    expect(makePresenter()->present($torrentJob))->toBe([
        'id' => 7,
        'title' => 'Movie',
        'status' => 'downloading',
        'downloaded_bytes' => 42,
        'total_bytes' => 100,
        'is_complete' => false,
        'message' => 'En cours',
        'source' => 'archive_org',
        'seeders' => 12,
        'peers' => 30,
        'format' => 'MKV',
        'transcode_status' => 'processing',
        'can_play' => false,
        'attempted_candidates' => [['source' => 'a', 'torrent_url' => 'http://a.test', 'message' => 'timed out']],
        'media_info' => ['width' => 1920, 'height' => 1080, 'duration_seconds' => 120.5, 'video_codec' => 'h264', 'audio_codec' => 'aac'],
    ]);
});

test('attempted_candidates defaults to an empty array when none exist', function () {
    $torrentJob = new TorrentJob(['file_path' => null]);

    expect(makePresenter()->present($torrentJob)['attempted_candidates'])->toBe([]);
});

test('format() returns null when there is no file path yet', function () {
    $torrentJob = new TorrentJob(['file_path' => null]);

    expect(makePresenter()->present($torrentJob)['format'])->toBeNull();
});

test('can_play truth table mirrors native-format-or-transcoded logic', function () {
    $presenter = makePresenter();

    $cases = [
        [0, '/shared/Movie/Movie.mp4', null, false],
        [10, '/shared/Movie/Movie.mp4', null, true],
        [10, '/shared/Movie/Movie.webm', null, true],
        [10, '/shared/Movie/Movie.mkv', null, false],
        [10, '/shared/Movie/Movie.mkv', 'processing', false],
        [10, '/shared/Movie/Movie.mkv', 'completed', true],
        [10, '/shared/Movie/Movie.mkv', 'failed', false],
        [10, '/shared/Movie/Movie.webm', 'skipped', true],
    ];

    foreach ($cases as [$downloadedBytes, $filePath, $transcodeStatus, $expected]) {
        $torrentJob = new TorrentJob([
            'downloaded_bytes' => $downloadedBytes,
            'file_path' => $filePath,
            'transcode_status' => $transcodeStatus,
        ]);

        expect($presenter->present($torrentJob)['can_play'])->toBe($expected);
    }
});

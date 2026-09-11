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
        'file_path' => '/shared/Movie/Movie.mkv',
        'transcode_status' => 'processing',
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
        'format' => 'MKV',
        'transcode_status' => 'processing',
        'can_play' => false,
    ]);
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

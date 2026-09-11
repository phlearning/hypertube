<?php

use App\Services\Torrent\TorrentCandidateSelector;

test('it returns null when there are no candidates', function () {
    expect((new TorrentCandidateSelector)->selectBest([]))->toBeNull();
});

test('it picks the candidate with the most seeders', function () {
    $weak = ['id' => 'weak', 'seeders' => 2, 'peers' => 10, 'format' => 'mp4'];
    $strong = ['id' => 'strong', 'seeders' => 20, 'peers' => 1, 'format' => 'avi'];

    $best = (new TorrentCandidateSelector)->selectBest([$weak, $strong]);

    expect($best['id'])->toBe('strong');
});

test('it breaks a seeders tie using peers', function () {
    $fewerPeers = ['id' => 'fewer-peers', 'seeders' => 5, 'peers' => 3, 'format' => 'mp4'];
    $morePeers = ['id' => 'more-peers', 'seeders' => 5, 'peers' => 8, 'format' => 'avi'];

    $best = (new TorrentCandidateSelector)->selectBest([$fewerPeers, $morePeers]);

    expect($best['id'])->toBe('more-peers');
});

test('it breaks a seeders and peers tie using the preferred format order', function () {
    $avi = ['id' => 'avi', 'seeders' => 5, 'peers' => 5, 'format' => 'avi'];
    $mkv = ['id' => 'mkv', 'seeders' => 5, 'peers' => 5, 'format' => 'mkv'];
    $mp4 = ['id' => 'mp4', 'seeders' => 5, 'peers' => 5, 'format' => 'mp4'];
    $unknown = ['id' => 'unknown', 'seeders' => 5, 'peers' => 5, 'format' => 'bin'];

    $best = (new TorrentCandidateSelector)->selectBest([$avi, $unknown, $mkv, $mp4]);

    expect($best['id'])->toBe('mp4');
});

test('an unrecognised format still loses to any known format', function () {
    $unknown = ['id' => 'unknown', 'seeders' => 5, 'peers' => 5, 'format' => 'wmv'];
    $avi = ['id' => 'avi', 'seeders' => 5, 'peers' => 5, 'format' => 'avi'];

    $best = (new TorrentCandidateSelector)->selectBest([$unknown, $avi]);

    expect($best['id'])->toBe('avi');
});

test('a candidate missing seeders/peers/format is treated as the weakest, not an error', function () {
    $bare = ['id' => 'bare'];
    $healthy = ['id' => 'healthy', 'seeders' => 1, 'peers' => 1, 'format' => 'avi'];

    $best = (new TorrentCandidateSelector)->selectBest([$bare, $healthy]);

    expect($best['id'])->toBe('healthy');
});

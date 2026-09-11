<?php

use App\Services\Torrent\TorrentHealthChecker;
use Illuminate\Support\Facades\Http;

function fakeTorrentBytes(string $name): string
{
    $info = 'd'
        .bstr('length').bint(100)
        .bstr('name').bstr($name)
        .bstr('piece length').bint(32768)
        .bstr('pieces').bstr(str_repeat('A', 20))
        .'e';

    return 'd'
        .bstr('announce').bstr('http://torrent-fixture:6969/announce')
        .bstr('info').$info
        .'e';
}

test('it reports seeders and peers from a tracker that exposes complete/incomplete counts', function () {
    Http::fake([
        'example.test/movie.torrent' => Http::response(fakeTorrentBytes('movie.mp4')),
        'torrent-fixture:6969/announce*' => Http::response(
            'd8:completei3e10:incompletei2e8:intervali1800e5:peers0:e'
        ),
    ]);

    $health = (new TorrentHealthChecker)->check('http://example.test/movie.torrent');

    expect($health['info_hash'])->toHaveLength(40);
    unset($health['info_hash']);
    expect($health)->toBe(['seeders' => 3, 'peers' => 5, 'name' => 'movie.mp4', 'format' => 'mp4']);
});

test('it falls back to counting compact peers when the tracker omits complete/incomplete', function () {
    $peers = str_repeat("\x7f\x00\x00\x01\x1a\xe1", 2);

    Http::fake([
        'example.test/movie.torrent' => Http::response(fakeTorrentBytes('movie.mkv')),
        'torrent-fixture:6969/announce*' => Http::response(
            'd8:intervali1800e5:peers'.strlen($peers).':'.$peers.'e'
        ),
    ]);

    $health = (new TorrentHealthChecker)->check('http://example.test/movie.torrent');

    expect($health['info_hash'])->toHaveLength(40);
    unset($health['info_hash']);
    expect($health)->toBe(['seeders' => 0, 'peers' => 2, 'name' => 'movie.mkv', 'format' => 'mkv']);
});

test('it returns zeroed health when the torrent file cannot be fetched', function () {
    Http::fake([
        'example.test/movie.torrent' => Http::response('', 404),
    ]);

    $health = (new TorrentHealthChecker)->check('http://example.test/movie.torrent');

    expect($health)->toBe(['seeders' => 0, 'peers' => 0, 'name' => '', 'format' => '', 'info_hash' => '']);
});

test('it returns zeroed health when the fetched bytes are not a valid torrent file', function () {
    Http::fake([
        'example.test/movie.torrent' => Http::response('not a torrent'),
    ]);

    $health = (new TorrentHealthChecker)->check('http://example.test/movie.torrent');

    expect($health)->toBe(['seeders' => 0, 'peers' => 0, 'name' => '', 'format' => '', 'info_hash' => '']);
});

test('it returns zeroed seeders/peers but still reports name/format/info_hash when the tracker announce fails', function () {
    Http::fake([
        'example.test/movie.torrent' => Http::response(fakeTorrentBytes('movie.avi')),
        'torrent-fixture:6969/announce*' => Http::response('', 500),
    ]);

    $health = (new TorrentHealthChecker)->check('http://example.test/movie.torrent');

    expect($health['info_hash'])->toHaveLength(40);
    unset($health['info_hash']);
    expect($health)->toBe(['seeders' => 0, 'peers' => 0, 'name' => 'movie.avi', 'format' => 'avi']);
});

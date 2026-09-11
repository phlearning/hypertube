<?php

use App\Services\Torrent\TorrentFile;

function torrentBytes(string $name, int $length = 100): string
{
    $info = 'd'
        .bstr('length').bint($length)
        .bstr('name').bstr($name)
        .bstr('piece length').bint(32768)
        .bstr('pieces').bstr(str_repeat('A', 20))
        .'e';

    return 'd'
        .bstr('announce').bstr('http://torrent-fixture:6969/announce')
        .bstr('info').$info
        .'e';
}

test('it parses announce url, info hash and file name from a single-file torrent', function () {
    $torrent = TorrentFile::fromBytes(torrentBytes('movie.mp4'));

    expect($torrent->announce)->toBe('http://torrent-fixture:6969/announce');
    expect($torrent->name)->toBe('movie.mp4');
    expect($torrent->infoHashHex())->toHaveLength(40);
});

test('the info hash only depends on the info dictionary bytes', function () {
    $first = TorrentFile::fromBytes(torrentBytes('movie.mp4'));
    $second = TorrentFile::fromBytes(torrentBytes('movie.mkv'));

    expect($first->infoHashHex())->not->toBe($second->infoHashHex());
});

test('it rejects a torrent file without an info dictionary', function () {
    TorrentFile::fromBytes('d8:announce4:teste');
})->throws(InvalidArgumentException::class);

<?php

use App\Services\Torrent\Bencode;

test('it decodes integers', function () {
    expect(Bencode::decode(bint(42)))->toBe(42);
    expect(Bencode::decode(bint(-7)))->toBe(-7);
});

test('it decodes strings', function () {
    expect(Bencode::decode(bstr('hypertube')))->toBe('hypertube');
    expect(Bencode::decode(bstr('')))->toBe('');
});

test('it decodes lists', function () {
    $encoded = 'l'.bint(1).bstr('two').bint(3).'e';

    expect(Bencode::decode($encoded))->toBe([1, 'two', 3]);
});

test('it decodes nested dictionaries preserving key order', function () {
    $encoded = 'd'.bstr('a').bint(1).bstr('b').bstr('nested').'e';

    expect(Bencode::decode($encoded))->toBe(['a' => 1, 'b' => 'nested']);
});

test('it decodes a top-level dictionary while preserving each value raw bytes', function () {
    $info = 'd'.bstr('name').bstr('movie.mp4').'e';
    $encoded = 'd'.bstr('announce').bstr('http://tracker.example.com/announce').bstr('info').$info.'e';

    $raw = Bencode::decodeTopLevelDictRaw($encoded);

    expect($raw)->toHaveKeys(['announce', 'info']);
    expect(Bencode::decode($raw['announce']))->toBe('http://tracker.example.com/announce');
    expect($raw['info'])->toBe($info);
});

test('it throws on malformed input', function () {
    Bencode::decode('not-bencode');
})->throws(InvalidArgumentException::class);

test('it rejects an integer with trailing garbage instead of silently truncating', function () {
    Bencode::decode('i12x3e');
})->throws(InvalidArgumentException::class);

test('it rejects an integer that overflows PHP\'s integer range instead of silently clamping', function () {
    Bencode::decode('i99999999999999999999e');
})->throws(InvalidArgumentException::class);

test('it rejects a negative string length instead of walking the offset backwards', function () {
    Bencode::decode('-1:x');
})->throws(InvalidArgumentException::class);

test('it rejects a string whose declared length exceeds the remaining data', function () {
    Bencode::decode('10:short');
})->throws(InvalidArgumentException::class);

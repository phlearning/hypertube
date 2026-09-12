<?php

use App\Services\Search\MovieTitle;

test('key() lowercases and trims the title', function () {
    expect(MovieTitle::key('  Big Buck Bunny  '))->toBe('big buck bunny');
});

test('key() treats different-case, differently-spaced titles as the same identity', function () {
    expect(MovieTitle::key('Big Buck Bunny'))->toBe(MovieTitle::key(' BIG buck BUNNY '));
});

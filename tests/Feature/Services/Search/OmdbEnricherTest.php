<?php

use App\Services\Search\OmdbEnricher;
use Illuminate\Support\Facades\Http;

test('it enriches a movie with rating, poster, and genre from OMDb', function () {
    config(['services.omdb.key' => 'test-key']);

    Http::fake([
        'omdbapi.com/*' => Http::response([
            'Response' => 'True',
            'imdbRating' => '7.8',
            'Poster' => 'https://example.test/poster.jpg',
            'Genre' => 'Horror, Thriller',
            'Year' => '1968',
        ]),
    ]);

    $results = (new OmdbEnricher)->enrich([
        ['title' => 'Night of the Living Dead', 'year' => null],
    ]);

    expect($results[0])->toMatchArray([
        'title' => 'Night of the Living Dead',
        'rating' => 7.8,
        'poster' => 'https://example.test/poster.jpg',
        'genre' => 'Horror, Thriller',
        'year' => 1968,
    ]);
});

test('it leaves rating, poster, and genre null when OMDb has no match', function () {
    config(['services.omdb.key' => 'test-key']);

    Http::fake([
        'omdbapi.com/*' => Http::response(['Response' => 'False']),
    ]);

    $results = (new OmdbEnricher)->enrich([
        ['title' => 'Some Obscure Film', 'year' => 1955],
    ]);

    expect($results[0])->toMatchArray([
        'rating' => null,
        'poster' => null,
        'genre' => null,
        'year' => 1955,
    ]);
});

test('it skips enrichment entirely when no API key is configured', function () {
    config(['services.omdb.key' => null]);

    Http::fake();

    $results = (new OmdbEnricher)->enrich([
        ['title' => 'Night of the Living Dead', 'year' => 1968],
    ]);

    expect($results[0])->toMatchArray(['rating' => null, 'poster' => null, 'genre' => null]);
    Http::assertNothingSent();
});

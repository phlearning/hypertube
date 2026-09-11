<?php

use App\Services\Search\MovieSearchCriteria;
use App\Services\Search\MovieSearchService;
use Illuminate\Support\Facades\Http;

function fakeSearchHttp(): void
{
    config(['services.omdb.key' => 'test-key']);

    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response([
            'response' => ['docs' => [
                ['identifier' => 'zebra-movie', 'title' => 'Zebra Crossing', 'year' => 2001, 'downloads' => 10],
                ['identifier' => 'apple-movie', 'title' => 'Apple Orchard', 'year' => 1999, 'downloads' => 900],
            ]],
        ]),
        'publicdomaintorrents.info/nshowcat.html*' => Http::response(
            '<a href="nshowmovie.html?movieid=1">Mango Grove</a>'
        ),
        'omdbapi.com/*' => Http::response(['Response' => 'False']),
    ]);
}

test('it merges results from both sources', function () {
    fakeSearchHttp();

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray([]));

    expect(collect($results->items())->pluck('title')->all())
        ->toEqualCanonicalizing(['Zebra Crossing', 'Apple Orchard', 'Mango Grove']);
});

test('it sorts by name ascending', function () {
    fakeSearchHttp();

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray(['sort' => 'name']));

    expect(collect($results->items())->pluck('title')->all())
        ->toBe(['Apple Orchard', 'Mango Grove', 'Zebra Crossing']);
});

test('it sorts by popularity by default when there is no query', function () {
    fakeSearchHttp();

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray([]));

    expect(collect($results->items())->pluck('title')->first())->toBe('Apple Orchard');
});

test('it filters by minimum year', function () {
    fakeSearchHttp();

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray(['year' => 1999]));

    expect(collect($results->items())->pluck('title')->all())->toBe(['Apple Orchard']);
});

test('it paginates results', function () {
    fakeSearchHttp();

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray(['per_page' => 2, 'page' => 1]));

    expect($results->items())->toHaveCount(2)
        ->and($results->total())->toBe(3)
        ->and($results->lastPage())->toBe(2);
});

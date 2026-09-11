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

test('it groups the same movie found on both sources into one entry with a candidate per source', function () {
    config(['services.omdb.key' => 'test-key']);

    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response([
            'response' => ['docs' => [
                ['identifier' => 'night-of-the-living-dead', 'title' => 'Night of the Living Dead', 'year' => 1968, 'downloads' => 500000],
            ]],
        ]),
        'publicdomaintorrents.info/nshowcat.html*' => Http::response(
            '<a href="nshowmovie.html?movieid=1">Night of the Living Dead</a>'
        ),
        'omdbapi.com/*' => Http::response(['Response' => 'False']),
    ]);

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray([]));

    expect($results->items())->toHaveCount(1);

    $movie = $results->items()[0];
    expect($movie['title'])->toBe('Night of the Living Dead')
        ->and($movie['year'])->toBe(1968)
        ->and($movie['candidates'])->toHaveCount(2)
        ->and(collect($movie['candidates'])->pluck('source')->all())
        ->toEqualCanonicalizing(['archive_org', 'public_domain_torrents']);
});

test('it keeps two different movies that share a title but have different known years separate', function () {
    config(['services.omdb.key' => 'test-key']);

    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response([
            'response' => ['docs' => [
                ['identifier' => 'the-thing-1982', 'title' => 'The Thing', 'year' => 1982, 'downloads' => 100],
                ['identifier' => 'the-thing-2011', 'title' => 'The Thing', 'year' => 2011, 'downloads' => 50],
            ]],
        ]),
        'publicdomaintorrents.info/nshowcat.html*' => Http::response('<html></html>'),
        'omdbapi.com/*' => Http::response(['Response' => 'False']),
    ]);

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray([]));

    expect($results->items())->toHaveCount(2);

    $years = collect($results->items())->pluck('year')->all();
    expect($years)->toEqualCanonicalizing([1982, 2011]);

    foreach ($results->items() as $movie) {
        expect($movie['candidates'])->toHaveCount(1);
    }
});

test('it paginates results', function () {
    fakeSearchHttp();

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray(['per_page' => 2, 'page' => 1]));

    expect($results->items())->toHaveCount(2)
        ->and($results->total())->toBe(3)
        ->and($results->lastPage())->toBe(2);
});

<?php

use App\Services\Search\MovieSearchCriteria;
use App\Services\Search\MovieSearchService;
use Illuminate\Support\Facades\Http;

test('a seeded query returns the fixture movies without hitting any real source', function () {
    $movies = [
        ['title' => 'Fixture Movie', 'year' => null, 'rating' => null, 'poster' => null, 'genre' => null, 'popularity' => 1, 'candidates' => []],
    ];

    $this->artisan('e2e:seed-search-cache', ['movies' => json_encode($movies), '--query' => 'fixture-query'])
        ->assertSuccessful();

    Http::fake(fn () => Http::response('', 500));

    $results = app(MovieSearchService::class)->search(MovieSearchCriteria::fromArray(['q' => 'fixture-query']));

    expect(collect($results->items())->pluck('title')->all())->toBe(['Fixture Movie']);
    Http::assertNothingSent();
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('e2e:seed-search-cache', ['movies' => '[]', '--query' => 'x'])->assertFailed();
});

<?php

namespace App\Console\Commands\E2e;

use App\Services\Search\MovieSearchCriteria;
use App\Services\Search\MovieSearchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Test-support only: pre-populates the exact cache entry
 * MovieSearchService::search() would have written, so the Playwright e2e
 * suite can put deterministic, repeatable movies (including deliberately
 * awkward ones — duplicate titles, missing years) in front of /library
 * without depending on archive.org/PublicDomainTorrents/OMDb being up or
 * returning any particular thing on the day the suite runs.
 */
#[Signature('e2e:seed-search-cache {movies : JSON array of movie result rows} {--query= : The q= value this fixture applies to}')]
#[Description('Pre-populate the MovieSearchService result cache for the Playwright e2e suite.')]
class SeedSearchCacheCommand extends Command
{
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run e2e:seed-search-cache in production.');

            return self::FAILURE;
        }

        $criteria = MovieSearchCriteria::fromArray(['q' => $this->option('query')]);

        /** @var array<int, array<string, mixed>> $movies */
        $movies = json_decode((string) $this->argument('movies'), associative: true, flags: JSON_THROW_ON_ERROR);

        Cache::put(MovieSearchService::cacheKeyFor($criteria), $movies, now()->addMinutes(5));

        $this->info('Search cache seeded for query: '.($this->option('query') ?? '(none)'));

        return self::SUCCESS;
    }
}

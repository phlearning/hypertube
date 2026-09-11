<?php

namespace App\Services\Search;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class MovieSearchService
{
    private const SORTABLE = ['name', 'year', 'rating', 'popularity'];

    /**
     * Results per source, and how long a given search's merged/enriched/sorted
     * result set is cached. Caching keeps infinite-scroll pages consistent with
     * each other (a live re-fetch per page could reorder or duplicate movies)
     * and avoids re-hitting archive.org/PublicDomainTorrents/OMDb on every
     * scroll tick for the same search.
     */
    private const MAX_RESULTS_PER_SOURCE = 100;

    private const CACHE_TTL_MINUTES = 5;

    public function __construct(
        private readonly ArchiveOrgSource $archiveOrg,
        private readonly PublicDomainTorrentsSource $publicDomainTorrents,
        private readonly OmdbEnricher $enricher,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function search(MovieSearchCriteria $criteria): LengthAwarePaginator
    {
        $results = Cache::remember(
            $this->cacheKey($criteria),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($criteria) {
                $results = [
                    ...$this->archiveOrg->search($criteria->query, self::MAX_RESULTS_PER_SOURCE),
                    ...$this->publicDomainTorrents->search($criteria->query, self::MAX_RESULTS_PER_SOURCE),
                ];

                $results = $this->enricher->enrich($results);
                $results = $this->group($results);
                $results = $this->filter($results, $criteria);

                return $this->sort($results, $criteria);
            }
        );

        return $this->paginate($results, $criteria->page, $criteria->perPage);
    }

    private function cacheKey(MovieSearchCriteria $criteria): string
    {
        return 'library:search:'.md5((string) json_encode([
            $criteria->query,
            $criteria->sort,
            $criteria->direction,
            $criteria->genre,
            $criteria->minRating,
            $criteria->year,
        ]));
    }

    /**
     * Collapse the same movie found across multiple sources into a single
     * entry carrying every source's torrent as a download candidate. Movies
     * are matched by title, and by year only when both sides report one —
     * PublicDomainTorrents never reports a year, so a null year is treated as
     * a wildcard, but two entries sharing a title with two different known
     * years (e.g. an original and a remake) are kept as separate movies
     * rather than silently merging one film's torrent into another's.
     *
     * @param  array<int, array<string, mixed>>  $movies
     * @return array<int, array<string, mixed>>
     */
    private function group(array $movies): array
    {
        $groups = [];

        foreach ($movies as $movie) {
            $titleKey = mb_strtolower(trim((string) $movie['title']));
            $index = $this->findCompatibleGroup($groups, $titleKey, $movie['year']);

            /** @var array<string, mixed> $group */
            $group = $index === null ? [
                'title_key' => $titleKey,
                'title' => $movie['title'],
                'year' => null,
                'rating' => null,
                'poster' => null,
                'genre' => null,
                'popularity' => 0,
                'candidates' => [],
            ] : $groups[$index];

            $group['year'] ??= $movie['year'];
            $group['rating'] ??= $movie['rating'];
            $group['poster'] ??= $movie['poster'];
            $group['genre'] ??= $movie['genre'];
            $group['popularity'] = max($group['popularity'], $movie['popularity']);
            $group['candidates'][] = [
                'source' => $movie['source'],
                'source_id' => $movie['source_id'],
                'torrent_url' => $movie['torrent_url'],
            ];

            if ($index === null) {
                $groups[] = $group;
            } else {
                $groups[$index] = $group;
            }
        }

        return array_values(array_map(
            fn (array $group) => array_diff_key($group, ['title_key' => null]),
            $groups
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function findCompatibleGroup(array $groups, string $titleKey, ?int $year): ?int
    {
        foreach ($groups as $index => $group) {
            if ($group['title_key'] !== $titleKey) {
                continue;
            }

            if ($group['year'] === null || $year === null || $group['year'] === $year) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $movies
     * @return array<int, array<string, mixed>>
     */
    private function filter(array $movies, MovieSearchCriteria $criteria): array
    {
        return array_values(array_filter($movies, function (array $movie) use ($criteria) {
            if ($criteria->genre && ! str_contains(mb_strtolower((string) $movie['genre']), mb_strtolower($criteria->genre))) {
                return false;
            }

            if ($criteria->minRating !== null && ($movie['rating'] === null || $movie['rating'] < $criteria->minRating)) {
                return false;
            }

            if ($criteria->year !== null && $movie['year'] !== $criteria->year) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $movies
     * @return array<int, array<string, mixed>>
     */
    private function sort(array $movies, MovieSearchCriteria $criteria): array
    {
        $key = in_array($criteria->sort, self::SORTABLE, true) ? $criteria->sort : 'name';
        $column = $key === 'name' ? 'title' : $key;
        $descending = $criteria->direction === 'desc';

        usort($movies, function (array $a, array $b) use ($column, $descending) {
            $left = $a[$column] ?? null;
            $right = $b[$column] ?? null;

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                $result = 1;
            } elseif ($right === null) {
                $result = -1;
            } else {
                $result = is_string($left) ? strcasecmp($left, $right) : $left <=> $right;
            }

            return $descending ? -$result : $result;
        });

        return $movies;
    }

    /**
     * @param  array<int, array<string, mixed>>  $movies
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(array $movies, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(
            array_slice($movies, ($page - 1) * $perPage, $perPage),
            count($movies),
            $perPage,
            $page,
        );
    }
}

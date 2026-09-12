<?php

namespace App\Services\Search;

use App\Models\TorrentJob;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class WatchedMovieAnnotator
{
    /**
     * Flags each movie on the page with whether it's been watched before,
     * matched by normalized title against local watch history. Deliberately
     * independent of whether the movie is still cached on disk (a purged
     * download is still a movie the user has seen) and computed outside
     * MovieSearchService's own cache, since watch history changes far more
     * often than the 5-minute-cached search results should.
     *
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $movies
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function annotate(LengthAwarePaginator $movies): LengthAwarePaginator
    {
        $watchedTitleKeys = $this->watchedTitleKeys();

        /** @var array<int, array<string, mixed>> $annotated */
        $annotated = array_map(function (array $movie) use ($watchedTitleKeys): array {
            $movie['watched'] = $watchedTitleKeys->contains(MovieTitle::key((string) $movie['title']));

            return $movie;
        }, $movies->items());

        return new LengthAwarePaginator(
            $annotated,
            $movies->total(),
            $movies->perPage(),
            $movies->currentPage(),
            $movies->getOptions(),
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function watchedTitleKeys(): Collection
    {
        return TorrentJob::query()
            ->where('type', 'download')
            ->whereNotNull('last_watched_at')
            ->pluck('title')
            ->map(fn (string $title) => MovieTitle::key($title))
            ->unique();
    }
}

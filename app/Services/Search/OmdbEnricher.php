<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OmdbEnricher
{
    private const ENDPOINT = 'https://www.omdbapi.com/';

    /**
     * @param  array<int, array<string, mixed>>  $movies
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $movies): array
    {
        $apiKey = config('services.omdb.key');

        if (! $apiKey) {
            return array_map(fn (array $movie) => [...$movie, 'rating' => null, 'poster' => null, 'genre' => null], $movies);
        }

        return array_map(function (array $movie) use ($apiKey) {
            $meta = $this->remember($movie, $apiKey);

            return [
                ...$movie,
                'rating' => $meta['rating'],
                'poster' => $meta['poster'],
                'genre' => $meta['genre'],
                'year' => $movie['year'] ?? $meta['year'],
            ];
        }, $movies);
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array{rating: ?float, poster: ?string, genre: ?string, year: ?int}
     */
    private function remember(array $movie, string $apiKey): array
    {
        $key = $this->cacheKey($movie);

        /** @var array{rating: ?float, poster: ?string, genre: ?string, year: ?int}|null $cached */
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $meta = $this->fetch((string) $movie['title'], $apiKey);

        // A confirmed match is cached for a day; an empty/failed lookup gets a
        // short TTL so a transient OMDb outage or rate limit doesn't blank a
        // movie's metadata for a full day.
        Cache::put($key, $meta, $meta['rating'] !== null ? now()->addDay() : now()->addMinutes(10));

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function cacheKey(array $movie): string
    {
        $normalizedTitle = mb_strtolower(trim((string) $movie['title']));

        return 'omdb:'.md5($normalizedTitle.'|'.($movie['year'] ?? ''));
    }

    /**
     * @return array{rating: ?float, poster: ?string, genre: ?string, year: ?int}
     */
    private function fetch(string $title, string $apiKey): array
    {
        $empty = ['rating' => null, 'poster' => null, 'genre' => null, 'year' => null];

        $response = Http::get(self::ENDPOINT, ['apikey' => $apiKey, 't' => $title]);

        if ($response->failed() || ($response->json('Response') === 'False')) {
            return $empty;
        }

        $data = $response->json();

        return [
            'rating' => is_numeric($data['imdbRating'] ?? null) ? (float) $data['imdbRating'] : null,
            'poster' => ($data['Poster'] ?? 'N/A') !== 'N/A' ? $data['Poster'] : null,
            'genre' => $data['Genre'] ?? null,
            'year' => is_numeric($data['Year'] ?? null) ? (int) $data['Year'] : null,
        ];
    }
}

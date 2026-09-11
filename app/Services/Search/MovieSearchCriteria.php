<?php

namespace App\Services\Search;

final readonly class MovieSearchCriteria
{
    public function __construct(
        public ?string $query = null,
        public string $sort = 'popularity',
        public string $direction = 'desc',
        public ?string $genre = null,
        public ?float $minRating = null,
        public ?int $year = null,
        public int $page = 1,
        public int $perPage = 24,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $query = $data['q'] ?? null;
        $sort = $data['sort'] ?? ($query ? 'name' : 'popularity');

        return new self(
            query: $query,
            sort: $sort,
            direction: $data['dir'] ?? ($sort === 'popularity' ? 'desc' : 'asc'),
            genre: $data['genre'] ?? null,
            minRating: isset($data['min_rating']) ? (float) $data['min_rating'] : null,
            year: isset($data['year']) ? (int) $data['year'] : null,
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 24),
        );
    }
}

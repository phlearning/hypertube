<?php

namespace App\Services\Search;

interface MovieSource
{
    /**
     * @return array<int, array{title: string, year: ?int, source: string, source_id: string, torrent_url: string, popularity: int}>
     */
    public function search(?string $query, int $limit = 50): array;
}

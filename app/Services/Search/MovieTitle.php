<?php

namespace App\Services\Search;

final class MovieTitle
{
    /**
     * Normalize a title for identity matching (grouping search results,
     * matching a download request against an already-downloaded movie,
     * matching a search result against watch history) — case and
     * surrounding whitespace shouldn't make two mentions of the same movie
     * look like different movies.
     */
    public static function key(string $title): string
    {
        return mb_strtolower(trim($title));
    }
}

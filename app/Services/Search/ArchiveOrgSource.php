<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Http;

class ArchiveOrgSource implements MovieSource
{
    private const ENDPOINT = 'https://archive.org/advancedsearch.php';

    /**
     * archive.org's own `mediatype:movies` tag is unreliable — ROM/software
     * preservation catalogs get mistagged as movies often enough (and even
     * carry auto-generated preview video formats) that the tag and file
     * format alone can't tell them apart from a real film. These are the
     * naming conventions of the preservation groups behind that specific
     * kind of catalog, not an attempt at a general classifier.
     *
     * @var array<int, string>
     */
    private const NON_MOVIE_TITLE_MARKERS = ['TOSEC', 'DAT Pack', 'ROM Set', 'No-Intro', 'Redump'];

    public function search(?string $query, int $limit = 50): array
    {
        $mediaFilter = 'mediatype:movies';
        $q = $query ? sprintf('title:("%s") AND %s', addslashes($query), $mediaFilter) : $mediaFilter;

        $response = Http::get(self::ENDPOINT, [
            'q' => $q,
            'fl' => ['identifier', 'title', 'year', 'downloads'],
            'sort' => $query ? 'title asc' : 'downloads desc',
            'rows' => $limit,
            'output' => 'json',
        ]);

        if ($response->failed()) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $docs */
        $docs = $response->json('response.docs', []);
        $docs = array_filter($docs, fn (array $doc) => ! empty($doc['identifier'])
            && ! empty($doc['title'])
            && ! $this->looksLikeNonMovieCatalog($doc['title']));

        return array_values(array_map(fn (array $doc) => [
            'title' => $doc['title'],
            'year' => isset($doc['year']) && is_numeric($doc['year']) ? (int) $doc['year'] : null,
            'source' => 'archive_org',
            'source_id' => $doc['identifier'],
            'torrent_url' => "https://archive.org/download/{$doc['identifier']}/{$doc['identifier']}_archive.torrent",
            'popularity' => (int) ($doc['downloads'] ?? 0),
        ], $docs));
    }

    private function looksLikeNonMovieCatalog(string $title): bool
    {
        foreach (self::NON_MOVIE_TITLE_MARKERS as $marker) {
            if (stripos($title, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}

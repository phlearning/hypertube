<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Http;

class ArchiveOrgSource implements MovieSource
{
    private const ENDPOINT = 'https://archive.org/advancedsearch.php';

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
        $docs = array_filter($docs, fn (array $doc) => ! empty($doc['identifier']) && ! empty($doc['title']));

        return array_values(array_map(fn (array $doc) => [
            'title' => $doc['title'],
            'year' => isset($doc['year']) && is_numeric($doc['year']) ? (int) $doc['year'] : null,
            'source' => 'archive_org',
            'source_id' => $doc['identifier'],
            'torrent_url' => "https://archive.org/download/{$doc['identifier']}/{$doc['identifier']}_archive.torrent",
            'popularity' => (int) ($doc['downloads'] ?? 0),
        ], $docs));
    }
}

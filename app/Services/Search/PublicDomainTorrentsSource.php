<?php

namespace App\Services\Search;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

class PublicDomainTorrentsSource implements MovieSource
{
    private const BASE_URL = 'https://www.publicdomaintorrents.info';

    public function search(?string $query, int $limit = 50): array
    {
        $response = Http::get(self::BASE_URL.'/nshowcat.html', ['category' => 'ALL']);

        if ($response->failed()) {
            return [];
        }

        $movies = $this->parseListing($response->body());

        if ($query) {
            $needle = mb_strtolower($query);
            $movies = array_values(array_filter(
                $movies,
                fn (array $movie) => str_contains(mb_strtolower($movie['title']), $needle)
            ));
        }

        return array_slice($movies, 0, $limit);
    }

    /**
     * @return array<int, array{title: string, year: ?int, source: string, source_id: string, torrent_url: string, popularity: int}>
     */
    private function parseListing(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[contains(@href, "nshowmovie.html?movieid=")]');
        $movies = [];

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $href = $node->getAttribute('href');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $params);
            $movieId = is_string($params['movieid'] ?? null) ? $params['movieid'] : null;
            $title = trim($node->textContent);

            if ($movieId === null || $title === '') {
                continue;
            }

            $movies[$movieId] = [
                'title' => $title,
                'year' => null,
                'source' => 'public_domain_torrents',
                'source_id' => $movieId,
                'torrent_url' => self::BASE_URL.'/bt/btdownload.php?type=torrent&file='.rawurlencode($title).'.avi.torrent',
                'popularity' => 0,
            ];
        }

        return array_values($movies);
    }
}

<?php

namespace App\Services\Torrent;

final class TorrentCandidateSelector
{
    /**
     * Preferred formats, most to least preferred. Anything else ranks lowest.
     */
    private const FORMAT_PREFERENCE = ['mp4', 'mkv', 'avi'];

    /**
     * Pick the healthiest candidate: most seeders wins, peers breaks a tie,
     * and the preferred file format breaks a further tie. Candidates may
     * carry extra keys (e.g. torrent_url) beyond the three used for ranking.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    public function selectBest(array $candidates): ?array
    {
        return $this->rank($candidates)[0] ?? null;
    }

    /**
     * Sort every candidate best-first (same ordering as selectBest), instead
     * of returning only the winner — used to build a fallback chain to try
     * in order if the best candidate turns out to be dead.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function rank(array $candidates): array
    {
        usort($candidates, fn (array $a, array $b) => [$b['seeders'] ?? 0, $b['peers'] ?? 0, self::formatRank($b['format'] ?? '')]
            <=> [$a['seeders'] ?? 0, $a['peers'] ?? 0, self::formatRank($a['format'] ?? '')]);

        return $candidates;
    }

    private static function formatRank(string $format): int
    {
        $index = array_search(mb_strtolower($format), self::FORMAT_PREFERENCE, true);

        return $index === false ? -1 : count(self::FORMAT_PREFERENCE) - $index;
    }
}

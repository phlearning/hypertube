<?php

namespace App\Services\Torrent;

use Illuminate\Support\Facades\Http;
use Throwable;

class TorrentHealthChecker
{
    private const PEER_ID_PREFIX = '-HT0001-';

    /**
     * @return array{seeders: int, peers: int, name: string, format: string}
     */
    public function check(string $torrentUrl): array
    {
        $empty = ['seeders' => 0, 'peers' => 0, 'name' => '', 'format' => ''];

        $torrent = $this->fetchTorrentFile($torrentUrl);

        if ($torrent === null) {
            return $empty;
        }

        $format = self::formatFromName($torrent->name);

        if ($torrent->announce === '') {
            return [...$empty, 'name' => $torrent->name, 'format' => $format];
        }

        [$seeders, $peers] = $this->announce($torrent);

        return ['seeders' => $seeders, 'peers' => $peers, 'name' => $torrent->name, 'format' => $format];
    }

    private function fetchTorrentFile(string $torrentUrl): ?TorrentFile
    {
        $bytes = $this->fetchBytes($torrentUrl);

        if ($bytes === null) {
            return null;
        }

        try {
            return TorrentFile::fromBytes($bytes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function announce(TorrentFile $torrent): array
    {
        $bytes = $this->fetchBytes($torrent->announce, [
            'info_hash' => $torrent->infoHash,
            'peer_id' => str_pad(self::PEER_ID_PREFIX, 20, '0'),
            'port' => 6881,
            'uploaded' => 0,
            'downloaded' => 0,
            'left' => 1,
            'compact' => 1,
            'numwant' => 0,
            'event' => 'started',
        ]);

        if ($bytes === null) {
            return [0, 0];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = Bencode::decode($bytes);
        } catch (Throwable) {
            return [0, 0];
        }

        if (isset($decoded['complete']) || isset($decoded['incomplete'])) {
            $seeders = (int) ($decoded['complete'] ?? 0);
            $peers = $seeders + (int) ($decoded['incomplete'] ?? 0);

            return [$seeders, $peers];
        }

        $peersField = $decoded['peers'] ?? '';
        $count = match (true) {
            is_string($peersField) => intdiv(strlen($peersField), 6),
            is_array($peersField) => count($peersField),
            default => 0,
        };

        return [0, $count];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchBytes(string $url, array $query = []): ?string
    {
        try {
            $response = Http::timeout(10)->get($url, $query);
        } catch (Throwable) {
            return null;
        }

        return $response->failed() ? null : $response->body();
    }

    private static function formatFromName(string $name): string
    {
        return mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}

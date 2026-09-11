<?php

namespace App\Services\Torrent;

use InvalidArgumentException;

final readonly class TorrentFile
{
    public function __construct(
        public string $announce,
        public string $infoHash,
        public string $name,
    ) {}

    public static function fromBytes(string $data): self
    {
        $topLevel = Bencode::decodeTopLevelDictRaw($data);

        if (! isset($topLevel['info'])) {
            throw new InvalidArgumentException('Torrent file is missing an info dictionary.');
        }

        /** @var array<string, mixed> $info */
        $info = Bencode::decode($topLevel['info']);
        $announce = isset($topLevel['announce']) ? Bencode::decode($topLevel['announce']) : '';

        return new self(
            announce: is_string($announce) ? $announce : '',
            infoHash: sha1($topLevel['info'], true),
            name: is_string($info['name'] ?? null) ? $info['name'] : '',
        );
    }

    public function infoHashHex(): string
    {
        return bin2hex($this->infoHash);
    }
}

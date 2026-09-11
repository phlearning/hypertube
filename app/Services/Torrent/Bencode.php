<?php

namespace App\Services\Torrent;

use InvalidArgumentException;

final class Bencode
{
    public static function decode(string $data): mixed
    {
        $offset = 0;

        return self::decodeValue($data, $offset);
    }

    /**
     * Decode a top-level dictionary, returning each entry's still-encoded raw
     * bytes instead of its parsed value. Needed to compute a torrent's info
     * hash, which is a SHA-1 of the exact bytes of the "info" entry.
     *
     * @return array<string, string>
     */
    public static function decodeTopLevelDictRaw(string $data): array
    {
        $offset = 0;

        if (($data[$offset] ?? null) !== 'd') {
            throw new InvalidArgumentException('Expected a bencoded dictionary.');
        }

        $offset++;
        $entries = [];

        while (($data[$offset] ?? 'e') !== 'e') {
            $key = self::decodeString($data, $offset);
            $start = $offset;
            self::decodeValue($data, $offset);
            $entries[$key] = substr($data, $start, $offset - $start);
        }

        return $entries;
    }

    private static function decodeValue(string $data, int &$offset): mixed
    {
        $type = $data[$offset] ?? null;

        return match (true) {
            $type === null => throw new InvalidArgumentException('Unexpected end of bencoded data.'),
            $type === 'i' => self::decodeInteger($data, $offset),
            $type === 'l' => self::decodeList($data, $offset),
            $type === 'd' => self::decodeDict($data, $offset),
            ctype_digit($type) => self::decodeString($data, $offset),
            default => throw new InvalidArgumentException("Unexpected bencode token '{$type}' at offset {$offset}."),
        };
    }

    private static function decodeInteger(string $data, int &$offset): int
    {
        $end = strpos($data, 'e', $offset);

        if ($end === false) {
            throw new InvalidArgumentException('Unterminated bencoded integer.');
        }

        $raw = substr($data, $offset + 1, $end - $offset - 1);

        if (! preg_match('/^-?(0|[1-9]\d*)$/', $raw)) {
            throw new InvalidArgumentException("Malformed bencoded integer '{$raw}'.");
        }

        $value = (int) $raw;

        if ((string) $value !== $raw) {
            throw new InvalidArgumentException("Bencoded integer '{$raw}' is out of range.");
        }

        $offset = $end + 1;

        return $value;
    }

    private static function decodeString(string $data, int &$offset): string
    {
        $colon = strpos($data, ':', $offset);

        if ($colon === false) {
            throw new InvalidArgumentException('Malformed bencoded string length.');
        }

        $rawLength = substr($data, $offset, $colon - $offset);

        if (! preg_match('/^\d+$/', $rawLength)) {
            throw new InvalidArgumentException("Malformed bencoded string length '{$rawLength}'.");
        }

        $length = (int) $rawLength;
        $offset = $colon + 1;
        $value = substr($data, $offset, $length);

        if (strlen($value) !== $length) {
            throw new InvalidArgumentException('Bencoded string is truncated.');
        }

        $offset += $length;

        return $value;
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeList(string $data, int &$offset): array
    {
        $offset++;
        $items = [];

        while (($data[$offset] ?? 'e') !== 'e') {
            $items[] = self::decodeValue($data, $offset);
        }

        $offset++;

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeDict(string $data, int &$offset): array
    {
        $offset++;
        $dict = [];

        while (($data[$offset] ?? 'e') !== 'e') {
            $key = self::decodeString($data, $offset);
            $dict[$key] = self::decodeValue($data, $offset);
        }

        $offset++;

        return $dict;
    }
}

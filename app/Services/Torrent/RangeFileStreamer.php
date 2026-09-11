<?php

namespace App\Services\Torrent;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RangeFileStreamer
{
    private const CHUNK_SIZE = 1024 * 1024;

    /**
     * Firefox (unlike Chromium, which sniffs the bytes) refuses to even
     * attempt playback of a <video> source served as application/octet-stream
     * — it needs a real video/* Content-Type to try at all.
     *
     * @var array<string, string>
     */
    private const CONTENT_TYPES = [
        'mp4' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'ogv' => 'video/ogg',
    ];

    /**
     * Serve a slice of a file over HTTP, honouring the Range protocol while
     * never reading past $availableBytes — the caller's guarantee of how
     * much of the file is safe to read (e.g. a torrent's downloaded_bytes).
     */
    public function stream(string $path, int $availableBytes, ?int $totalBytes, ?string $rangeHeader): Response
    {
        [$start, $end, $satisfiable, $isPartial] = $this->resolveRange($rangeHeader, $availableBytes);

        if (! $satisfiable) {
            return $this->notSatisfiableResponse($totalBytes);
        }

        $length = $end - $start + 1;

        $response = new StreamedResponse(
            fn () => $this->emit($path, $start, $length),
            $isPartial ? 206 : 200,
        );

        $response->headers->set('Content-Type', $this->resolveContentType($path));
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Content-Length', (string) $length);

        if ($isPartial) {
            $response->headers->set('Content-Range', sprintf(
                'bytes %d-%d/%s',
                $start,
                $end,
                $totalBytes !== null ? (string) $totalBytes : '*',
            ));
        }

        return $response;
    }

    /**
     * @return array{0: int, 1: int, 2: bool, 3: bool} [$start, $end, $satisfiable, $isPartial]
     */
    private function resolveRange(?string $rangeHeader, int $availableBytes): array
    {
        if ($rangeHeader === null) {
            return [0, max($availableBytes - 1, -1), true, false];
        }

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches) || ($matches[1] === '' && $matches[2] === '')) {
            // Malformed, or a form we don't support (e.g. multi-range with
            // commas): per RFC 7233 a server may ignore an unusable Range
            // header and serve the full representation instead of erroring.
            return [0, max($availableBytes - 1, -1), true, false];
        }

        if ($availableBytes <= 0) {
            return $this->unsatisfiable();
        }

        [, $rawStart, $rawEnd] = $matches;

        if ($rawStart === '') {
            $suffixLength = (int) $rawEnd;

            if ($suffixLength <= 0) {
                return $this->unsatisfiable();
            }

            return [max($availableBytes - $suffixLength, 0), $availableBytes - 1, true, true];
        }

        $start = (int) $rawStart;

        if ($start >= $availableBytes) {
            return $this->unsatisfiable();
        }

        $end = min($rawEnd === '' ? $availableBytes - 1 : (int) $rawEnd, $availableBytes - 1);

        if ($end < $start) {
            return $this->unsatisfiable();
        }

        return [$start, $end, true, true];
    }

    /**
     * @return array{0: int, 1: int, 2: bool, 3: bool}
     */
    private function unsatisfiable(): array
    {
        return [0, 0, false, false];
    }

    private function resolveContentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }

    private function notSatisfiableResponse(?int $totalBytes): Response
    {
        return new Response('', 416, [
            'Accept-Ranges' => 'bytes',
            'Content-Range' => 'bytes */'.($totalBytes !== null ? (string) $totalBytes : '*'),
        ]);
    }

    private function emit(string $path, int $start, int $length): void
    {
        if ($length <= 0) {
            return;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            // This class is deliberately container-free (pure, Unit-tested
            // without booting Laravel), so a plain error_log() is used here
            // instead of the Log facade.
            error_log("RangeFileStreamer: could not open file for streaming: {$path}");

            return;
        }

        if (fseek($handle, $start) !== 0) {
            error_log("RangeFileStreamer: could not seek to offset {$start} in {$path}");
            fclose($handle);

            return;
        }

        $remaining = $length;

        while ($remaining > 0 && ! feof($handle)) {
            $chunk = fread($handle, min(self::CHUNK_SIZE, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }

        fclose($handle);
    }
}

<?php

namespace App\Services\Torrent;

use App\Models\TorrentJob;

class TorrentJobPresenter
{
    public function __construct(private readonly VideoTranscoder $transcoder) {}

    /**
     * The shape both the Inertia download page and the real-time progress
     * broadcast need — kept in one place so the two can never drift apart.
     *
     * @return array{id: ?int, title: ?string, status: string, downloaded_bytes: int, total_bytes: ?int, is_complete: bool, message: ?string, source: ?string, seeders: ?int, peers: ?int, format: ?string, transcode_status: ?string, can_play: bool, attempted_candidates: array<int, array<string, mixed>>, media_info: ?array<string, mixed>}
     */
    public function present(TorrentJob $torrentJob): array
    {
        return [
            'id' => $torrentJob->id,
            'title' => $torrentJob->title,
            'status' => $torrentJob->status,
            'downloaded_bytes' => $torrentJob->downloaded_bytes,
            'total_bytes' => $torrentJob->total_bytes,
            'is_complete' => $torrentJob->is_complete,
            'message' => $torrentJob->message,
            'source' => $torrentJob->source,
            'seeders' => $torrentJob->seeders,
            'peers' => $torrentJob->peers,
            'format' => $this->formatOf($torrentJob->file_path),
            'transcode_status' => $torrentJob->transcode_status,
            'can_play' => $this->canPlay($torrentJob),
            'attempted_candidates' => $torrentJob->attempted_candidates ?? [],
            'media_info' => $torrentJob->media_info,
        ];
    }

    /**
     * Whether there's something worth pointing a <video> element at right
     * now: bytes exist, and either the format is one browsers already
     * handle natively, or transcoding has produced (or determined it
     * doesn't need to produce) a playable file.
     */
    private function canPlay(TorrentJob $torrentJob): bool
    {
        if ($torrentJob->downloaded_bytes <= 0) {
            return false;
        }

        if (in_array($torrentJob->transcode_status, ['completed', 'skipped'], true)) {
            return true;
        }

        if ($torrentJob->file_path === null) {
            return false;
        }

        // A format VideoTranscoder wouldn't need a full re-encode for
        // ('skip' or 'remux') is one browsers can already attempt natively,
        // even before transcoding has run — the same domain knowledge
        // VideoTranscoder itself uses to decide what ffmpeg work is needed.
        return $this->transcoder->planFor($torrentJob->file_path) !== 'transcode';
    }

    private function formatOf(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $extension = $this->transcoder->extension($path);

        return $extension === '' ? null : strtoupper($extension);
    }
}

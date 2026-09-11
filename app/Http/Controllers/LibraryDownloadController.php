<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadRequest;
use App\Models\TorrentJob;
use App\Services\Torrent\DownloadScheduler;
use App\Services\Torrent\RangeFileStreamer;
use App\Services\Torrent\TorrentCandidateSelector;
use App\Services\Torrent\TorrentHealthChecker;
use App\Services\Torrent\VideoTranscoder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class LibraryDownloadController extends Controller
{
    public function __construct(
        private readonly TorrentHealthChecker $healthChecker,
        private readonly TorrentCandidateSelector $selector,
        private readonly DownloadScheduler $scheduler,
        private readonly RangeFileStreamer $streamer,
        private readonly VideoTranscoder $transcoder,
    ) {}

    public function store(DownloadRequest $request): RedirectResponse
    {
        /** @var array<int, array{source: string, source_id: string, torrent_url: string}> $candidates */
        $candidates = $request->validated('candidates');
        $title = $request->validated('title');

        $enriched = array_map(fn (array $candidate) => [
            ...$candidate,
            ...$this->healthChecker->check($candidate['torrent_url']),
        ], $candidates);

        // A candidate whose torrent file couldn't be fetched/parsed reports an
        // empty name; only reachable candidates are eligible at all.
        $reachable = array_values(array_filter($enriched, fn (array $candidate) => $candidate['name'] !== ''));

        // Prefer candidates with observed swarm activity — a 0 seeder/0 peer
        // candidate is already known dead, so skip straight past it instead
        // of waiting for the worker to time out on it. If every reachable
        // candidate looks dead, still try them in ranked order rather than
        // refusing outright: the tracker snapshot can be stale.
        $healthy = array_values(array_filter(
            $reachable,
            fn (array $candidate) => $candidate['seeders'] > 0 || $candidate['peers'] > 0
        ));

        $chain = $this->selector->rank($healthy !== [] ? $healthy : $reachable);

        if ($chain === []) {
            return back()->withErrors([
                'candidates' => 'No downloadable candidate is currently available for this movie.',
            ]);
        }

        $first = array_shift($chain);

        try {
            [$torrentJob, $isNew] = Cache::lock('torrent-download-dedup:'.$first['info_hash'], 10)->block(5, function () use ($title, $first, $chain) {
                $existing = TorrentJob::query()
                    ->where('type', 'download')
                    ->where('info_hash', $first['info_hash'])
                    ->whereIn('status', TorrentJob::ACTIVE_STATUSES)
                    ->first();

                if ($existing !== null) {
                    return [$existing, false];
                }

                $job = TorrentJob::create([
                    'job_id' => (string) Str::uuid(),
                    'type' => 'download',
                    'title' => $title,
                    'status' => 'queued',
                    'torrent_url' => $first['torrent_url'],
                    'source' => $first['source'],
                    'info_hash' => $first['info_hash'],
                    'remaining_candidates' => $chain,
                ]);

                return [$job, true];
            });
        } catch (LockTimeoutException) {
            return back()->withErrors([
                'candidates' => 'This movie is busy right now, please try again in a moment.',
            ]);
        }

        // Admission is deliberately done outside the dedup lock above: it
        // takes its own lock and dispatches a job, and there is no need to
        // hold the (unrelated) per-movie dedup lock for that whole duration.
        if ($isNew) {
            $this->scheduler->admit($torrentJob);
        }

        return to_route('library.downloads.show', $torrentJob);
    }

    public function show(TorrentJob $torrentJob): InertiaResponse
    {
        abort_unless($torrentJob->type === 'download', 404);

        return Inertia::render('library/downloads/show', [
            'download' => [
                'id' => $torrentJob->id,
                'title' => $torrentJob->title,
                'status' => $torrentJob->status,
                'downloaded_bytes' => $torrentJob->downloaded_bytes,
                'total_bytes' => $torrentJob->total_bytes,
                'is_complete' => $torrentJob->is_complete,
                'message' => $torrentJob->message,
                'source' => $torrentJob->source,
                'format' => $this->formatOf($torrentJob->file_path),
                'transcode_status' => $torrentJob->transcode_status,
                'can_play' => $this->canPlay($torrentJob),
            ],
        ]);
    }

    public function stream(Request $request, TorrentJob $torrentJob): Response
    {
        abort_unless($torrentJob->type === 'download', 404);
        abort_if($torrentJob->file_path === null, 404);

        [$path, $availableBytes, $totalBytes] = $this->resolveStreamTarget($torrentJob);

        return $this->streamer->stream($path, $availableBytes, $totalBytes, $request->header('Range'));
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

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === '' ? null : strtoupper($extension);
    }

    /**
     * @return array{0: string, 1: int, 2: ?int}
     */
    private function resolveStreamTarget(TorrentJob $torrentJob): array
    {
        if ($torrentJob->playback_path === null) {
            return [$torrentJob->file_path, $torrentJob->downloaded_bytes, $torrentJob->total_bytes];
        }

        // The transcoded output is only ever written once fully done
        // (transcoding starts after the source download itself completes),
        // so it's always safe to serve in full. Its size is unrelated to
        // downloaded_bytes/total_bytes, which describe the original,
        // pre-transcode file.
        $size = @filesize($torrentJob->playback_path);

        if ($size === false) {
            return [$torrentJob->playback_path, 0, null];
        }

        return [$torrentJob->playback_path, $size, $size];
    }
}

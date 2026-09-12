<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadRequest;
use App\Models\TorrentJob;
use App\Services\Torrent\DownloadScheduler;
use App\Services\Torrent\RangeFileStreamer;
use App\Services\Torrent\TorrentCandidateSelector;
use App\Services\Torrent\TorrentHealthChecker;
use App\Services\Torrent\TorrentJobPresenter;
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
        private readonly TorrentJobPresenter $presenter,
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
                    'seeders' => $first['seeders'],
                    'peers' => $first['peers'],
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
            'download' => $this->presenter->present($torrentJob),
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

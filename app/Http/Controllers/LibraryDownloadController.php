<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadRequest;
use App\Models\TorrentJob;
use App\Services\Torrent\DownloadScheduler;
use App\Services\Torrent\TorrentCandidateSelector;
use App\Services\Torrent\TorrentHealthChecker;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LibraryDownloadController extends Controller
{
    public function __construct(
        private readonly TorrentHealthChecker $healthChecker,
        private readonly TorrentCandidateSelector $selector,
        private readonly DownloadScheduler $scheduler,
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

    public function show(TorrentJob $torrentJob): Response
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
            ],
        ]);
    }
}

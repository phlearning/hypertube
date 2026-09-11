<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadRequest;
use App\Jobs\StartTorrentDownload;
use App\Models\TorrentJob;
use App\Services\Torrent\TorrentCandidateSelector;
use App\Services\Torrent\TorrentHealthChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LibraryDownloadController extends Controller
{
    public function __construct(
        private readonly TorrentHealthChecker $healthChecker,
        private readonly TorrentCandidateSelector $selector,
    ) {}

    public function store(DownloadRequest $request): RedirectResponse
    {
        /** @var array<int, array{source: string, source_id: string, torrent_url: string}> $candidates */
        $candidates = $request->validated('candidates');

        $enriched = array_map(fn (array $candidate) => [
            ...$candidate,
            ...$this->healthChecker->check($candidate['torrent_url']),
        ], $candidates);

        // A candidate whose torrent file couldn't be fetched/parsed reports an
        // empty name; only reachable candidates are eligible for selection, so
        // an all-unreachable list correctly falls through to the error below.
        $reachable = array_values(array_filter($enriched, fn (array $candidate) => $candidate['name'] !== ''));

        $best = $this->selector->selectBest($reachable);

        if ($best === null) {
            return back()->withErrors([
                'candidates' => 'No downloadable candidate is currently available for this movie.',
            ]);
        }

        $torrentJob = TorrentJob::create([
            'job_id' => (string) Str::uuid(),
            'type' => 'download',
            'title' => $request->validated('title'),
            'status' => 'pending',
            'torrent_url' => $best['torrent_url'],
        ]);

        StartTorrentDownload::dispatch($torrentJob);

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

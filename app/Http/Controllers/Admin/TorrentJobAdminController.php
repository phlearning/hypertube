<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClearTorrentJobsRequest;
use App\Models\TorrentJob;
use App\Services\Torrent\TorrentJobCleaner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TorrentJobAdminController extends Controller
{
    public function __construct(private readonly TorrentJobCleaner $cleaner) {}

    public function index(): InertiaResponse
    {
        return Inertia::render('admin/torrent-jobs', [
            'counts' => [
                'total' => TorrentJob::query()->count(),
                'active' => TorrentJob::query()->whereIn('status', TorrentJob::ACTIVE_STATUSES)->count(),
                'failed' => TorrentJob::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function clear(ClearTorrentJobsRequest $request): RedirectResponse
    {
        $deleted = $this->cleaner->clear(
            $request->validated('scope'),
            $request->boolean('delete_files'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('{0} Aucun téléchargement ne correspondait à ce filtre.|{1} 1 téléchargement supprimé.|[2,*] :count téléchargements supprimés.', $deleted, ['count' => $deleted]),
        ]);

        return back();
    }
}

<?php

use App\Http\Controllers\Internal\TorrentWorkerCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('torrent-worker/callback', [TorrentWorkerCallbackController::class, 'store'])
    ->name('internal.torrent-worker.callback');

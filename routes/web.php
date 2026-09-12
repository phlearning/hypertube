<?php

use App\Http\Controllers\Admin\TorrentJobAdminController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\LibraryDownloadController;
use App\Http\Controllers\Settings\ProfilePictureController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('socialite.redirect');
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('socialite.callback');
Route::whereIn('provider', ['github', 'fortytwo']);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::post('/library/downloads', [LibraryDownloadController::class, 'store'])->name('library.downloads.store');
    Route::get('/library/downloads/{torrentJob}', [LibraryDownloadController::class, 'show'])->name('library.downloads.show');
    Route::get('/library/downloads/{torrentJob}/stream', [LibraryDownloadController::class, 'stream'])->name('library.downloads.stream');
    Route::patch('/updateavatar', [ProfilePictureController::class, 'update'])->name('update.avatar');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/torrent-jobs', [TorrentJobAdminController::class, 'index'])->name('torrent-jobs.index');
        Route::post('/torrent-jobs/clear', [TorrentJobAdminController::class, 'clear'])->name('torrent-jobs.clear');
    });
});

require __DIR__.'/settings.php';

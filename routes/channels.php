<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// TorrentJob has no owner column and its show/stream routes are already
// gated only by ['auth', 'verified'] with no per-job ownership check — the
// progress channel mirrors that same access level rather than inventing a
// stricter rule the rest of the feature doesn't enforce.
Broadcast::channel('torrent-job.{id}', function ($user) {
    return $user !== null;
});

<?php

use App\Events\TorrentJobProgressUpdated;
use App\Models\TorrentJob;
use Illuminate\Support\Facades\Event;

test('updateManyAndBroadcast touching a relevant field broadcasts for each affected row', function () {
    Event::fake([TorrentJobProgressUpdated::class]);
    $a = makeTorrentJob(['status' => 'downloading']);
    $b = makeTorrentJob(['status' => 'downloading']);

    $affected = TorrentJob::updateManyAndBroadcast([$a->id, $b->id], ['status' => 'failed']);

    expect($affected)->toBe(2);
    Event::assertDispatchedTimes(TorrentJobProgressUpdated::class, 2);
    expect([$a->fresh()->status, $b->fresh()->status])->toBe(['failed', 'failed']);
});

test('updateManyAndBroadcast touching only an irrelevant field does not broadcast', function () {
    Event::fake([TorrentJobProgressUpdated::class]);
    $job = makeTorrentJob();

    TorrentJob::updateManyAndBroadcast([$job->id], ['title' => 'New title']);

    Event::assertNotDispatched(TorrentJobProgressUpdated::class);
});

test('updateManyAndBroadcast matching zero rows does not error or broadcast', function () {
    Event::fake([TorrentJobProgressUpdated::class]);

    $affected = TorrentJob::updateManyAndBroadcast([-1], ['status' => 'failed']);

    expect($affected)->toBe(0);
    Event::assertNotDispatched(TorrentJobProgressUpdated::class);
});

test('updateManyAndBroadcast of only throttled fields still respects the broadcast throttle', function () {
    Event::fake([TorrentJobProgressUpdated::class]);
    $job = makeTorrentJob(['downloaded_bytes' => 0]);
    $job->update(['downloaded_bytes' => 5]);

    TorrentJob::updateManyAndBroadcast([$job->id], ['downloaded_bytes' => 10]);

    Event::assertDispatchedTimes(TorrentJobProgressUpdated::class, 1);
});

test('an ordinary bulk query update (bypassing the helper) still does not broadcast, by design', function () {
    Event::fake([TorrentJobProgressUpdated::class]);
    $job = makeTorrentJob(['status' => 'downloading']);

    TorrentJob::where('id', $job->id)->update(['status' => 'failed']);

    Event::assertNotDispatched(TorrentJobProgressUpdated::class);
});

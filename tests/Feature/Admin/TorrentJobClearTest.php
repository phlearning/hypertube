<?php

use App\Enums\UserRole;
use App\Models\TorrentJob;
use App\Models\User;

test('guests cannot access the admin torrent jobs page', function () {
    $this->get(route('admin.torrent-jobs.index'))->assertRedirect(route('login'));
});

test('a non-admin authenticated user is forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.torrent-jobs.index'))->assertForbidden();
    $this->actingAs($user)
        ->post(route('admin.torrent-jobs.clear'), ['scope' => 'all', 'delete_files' => false])
        ->assertForbidden();
});

test('an admin can view the page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.torrent-jobs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/torrent-jobs'));
});

test('an admin can clear all torrent jobs', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    makeTorrentJob(['status' => 'completed']);
    makeTorrentJob(['status' => 'downloading']);

    $this->actingAs($admin)
        ->post(route('admin.torrent-jobs.clear'), ['scope' => 'all', 'delete_files' => false])
        ->assertRedirect();

    expect(TorrentJob::count())->toBe(0);
});

test('an admin clearing the "stuck" scope only removes failed/stale jobs', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $failed = makeTorrentJob(['status' => 'failed']);
    $completed = makeTorrentJob(['status' => 'completed']);

    $this->actingAs($admin)->post(route('admin.torrent-jobs.clear'), ['scope' => 'stuck', 'delete_files' => false]);

    expect(TorrentJob::find($failed->id))->toBeNull();
    expect(TorrentJob::find($completed->id))->not->toBeNull();
});

test('scope is required and must be a known value', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->post(route('admin.torrent-jobs.clear'), ['scope' => 'bogus', 'delete_files' => false])
        ->assertSessionHasErrors('scope');
});

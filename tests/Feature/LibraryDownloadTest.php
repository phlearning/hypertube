<?php

use App\Jobs\StartTorrentDownload;
use App\Models\TorrentJob;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function fakeDownloadCandidatesHttp(): void
{
    $weakTorrent = 'd'.bstr('announce').bstr('http://tracker.test/announce').bstr('info')
        .('d'.bstr('length').bint(100).bstr('name').bstr('movie.avi').bstr('piece length').bint(32768).bstr('pieces').bstr(str_repeat('A', 20)).'e')
        .'e';
    $strongTorrent = 'd'.bstr('announce').bstr('http://tracker.test/announce').bstr('info')
        .('d'.bstr('length').bint(100).bstr('name').bstr('movie.mp4').bstr('piece length').bint(32768).bstr('pieces').bstr(str_repeat('B', 20)).'e')
        .'e';

    Http::fake([
        'source-a.test/movie.torrent' => Http::response($weakTorrent),
        'source-b.test/movie.torrent' => Http::response($strongTorrent),
        'tracker.test/announce*' => Http::sequence()
            ->push('d8:completei1e10:incompletei0e5:peers0:e')
            ->push('d8:completei9e10:incompletei0e5:peers0:e'),
    ]);
}

test('guests cannot start a download', function () {
    $this
        ->post(route('library.downloads.store'), ['title' => 'Movie', 'candidates' => []])
        ->assertRedirect(route('login'));
});

test('title and at least one candidate are required', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('library.downloads.store'), ['title' => '', 'candidates' => []])
        ->assertSessionHasErrors(['title', 'candidates']);
});

test('an authenticated user can start a download and the healthiest candidate is selected', function () {
    Queue::fake();
    fakeDownloadCandidatesHttp();
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('library.downloads.store'), [
            'title' => 'Movie',
            'candidates' => [
                ['source' => 'source_a', 'source_id' => '1', 'torrent_url' => 'http://source-a.test/movie.torrent'],
                ['source' => 'source_b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent'],
            ],
        ]);

    $torrentJob = TorrentJob::sole();
    $response->assertRedirect(route('library.downloads.show', $torrentJob));

    expect($torrentJob)
        ->type->toBe('download')
        ->title->toBe('Movie')
        ->status->toBe('pending')
        ->torrent_url->toBe('http://source-b.test/movie.torrent')
        ->source->toBe('source_b')
        ->info_hash->not->toBeEmpty();

    Queue::assertPushed(StartTorrentDownload::class);
});

test('two requests for the same movie deduplicate onto a single active download', function () {
    Queue::fake();
    fakeDownloadCandidatesHttp();
    $user = User::factory()->create();

    $payload = [
        'title' => 'Movie',
        'candidates' => [
            ['source' => 'source_b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent'],
        ],
    ];

    $first = $this->actingAs($user)->post(route('library.downloads.store'), $payload);
    $second = $this->actingAs($user)->post(route('library.downloads.store'), $payload);

    expect(TorrentJob::count())->toBe(1);

    $torrentJob = TorrentJob::sole();
    $first->assertRedirect(route('library.downloads.show', $torrentJob));
    $second->assertRedirect(route('library.downloads.show', $torrentJob));

    Queue::assertPushed(StartTorrentDownload::class, 1);
});

test('a 4th concurrent download request is queued instead of started immediately', function () {
    Queue::fake();
    fakeDownloadCandidatesHttp();
    $user = User::factory()->create();

    makeTorrentJob(['title' => 'Other 1', 'status' => 'pending']);
    makeTorrentJob(['title' => 'Other 2', 'status' => 'downloading']);
    makeTorrentJob(['title' => 'Other 3', 'status' => 'pending']);

    $this->actingAs($user)->post(route('library.downloads.store'), [
        'title' => 'Movie',
        'candidates' => [
            ['source' => 'source_b', 'source_id' => '2', 'torrent_url' => 'http://source-b.test/movie.torrent'],
        ],
    ]);

    $fourth = TorrentJob::where('title', 'Movie')->sole();
    expect($fourth->status)->toBe('queued');
    Queue::assertNotPushed(StartTorrentDownload::class);
});

test('more than 5 candidates are rejected', function () {
    $user = User::factory()->create();

    $candidates = array_map(
        fn (int $i) => ['source' => "source_{$i}", 'source_id' => (string) $i, 'torrent_url' => "http://source-{$i}.test/movie.torrent"],
        range(1, 6)
    );

    $this
        ->actingAs($user)
        ->post(route('library.downloads.store'), ['title' => 'Movie', 'candidates' => $candidates])
        ->assertSessionHasErrors('candidates');
});

test('it fails gracefully when no candidate can be reached', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('', 404)]);
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('library.downloads.store'), [
            'title' => 'Movie',
            'candidates' => [
                ['source' => 'source_a', 'source_id' => '1', 'torrent_url' => 'http://source-a.test/movie.torrent'],
            ],
        ])
        ->assertSessionHasErrors('candidates');

    Queue::assertNothingPushed();
    expect(TorrentJob::count())->toBe(0);
});

test('an authenticated user can see a download status page', function () {
    $user = User::factory()->create();
    $torrentJob = TorrentJob::create([
        'job_id' => (string) Str::uuid(),
        'type' => 'download',
        'status' => 'downloading',
        'torrent_url' => 'http://source-a.test/movie.torrent',
        'source' => 'archive_org',
        'downloaded_bytes' => 42,
        'total_bytes' => 100,
        'file_path' => '/shared/Movie/Movie.mkv',
        'transcode_status' => 'processing',
    ]);

    $this
        ->actingAs($user)
        ->get(route('library.downloads.show', $torrentJob))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('library/downloads/show')
                ->where('download.status', 'downloading')
                ->where('download.downloaded_bytes', 42)
                ->where('download.total_bytes', 100)
                ->where('download.source', 'archive_org')
                ->where('download.format', 'MKV')
                ->where('download.transcode_status', 'processing')
        );
});

test('can_play reflects whether the format is natively playable or transcoding has produced something playable', function () {
    $user = User::factory()->create();

    $cases = [
        // [downloaded_bytes, file_path, transcode_status, expected can_play]
        [0, '/shared/Movie/Movie.mp4', null, false],
        [10, '/shared/Movie/Movie.mp4', null, true],
        [10, '/shared/Movie/Movie.webm', null, true],
        [10, '/shared/Movie/Movie.mkv', null, false],
        [10, '/shared/Movie/Movie.mkv', 'processing', false],
        [10, '/shared/Movie/Movie.mkv', 'completed', true],
        [10, '/shared/Movie/Movie.mkv', 'failed', false],
        [10, '/shared/Movie/Movie.webm', 'skipped', true],
    ];

    foreach ($cases as [$downloadedBytes, $filePath, $transcodeStatus, $expected]) {
        $torrentJob = TorrentJob::create([
            'job_id' => (string) Str::uuid(),
            'type' => 'download',
            'status' => 'downloading',
            'torrent_url' => 'http://source-a.test/movie.torrent',
            'downloaded_bytes' => $downloadedBytes,
            'file_path' => $filePath,
            'transcode_status' => $transcodeStatus,
        ]);

        $this
            ->actingAs($user)
            ->get(route('library.downloads.show', $torrentJob))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('download.can_play', $expected));
    }
});

test('a ping job cannot be viewed as a download', function () {
    $user = User::factory()->create();
    $pingJob = TorrentJob::create(['job_id' => (string) Str::uuid(), 'type' => 'ping', 'status' => 'completed']);

    $this
        ->actingAs($user)
        ->get(route('library.downloads.show', $pingJob))
        ->assertNotFound();
});

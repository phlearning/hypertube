<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

function fakeLibraryHttp(): void
{
    config(['services.omdb.key' => 'test-key']);

    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response([
            'response' => ['docs' => [
                ['identifier' => 'night-of-the-living-dead', 'title' => 'Night of the Living Dead', 'year' => 1968, 'downloads' => 500000],
            ]],
        ]),
        'publicdomaintorrents.info/nshowcat.html*' => Http::response(
            '<a href="nshowmovie.html?movieid=1">His Girl Friday</a>'
        ),
        'omdbapi.com/*' => Http::response(['Response' => 'False']),
    ]);
}

test('guests cannot access the library', function () {
    fakeLibraryHttp();

    $this->get(route('library.index'))->assertRedirect(route('login'));
});

test('an authenticated user can search the library across both sources', function () {
    fakeLibraryHttp();
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('library.index', ['q' => 'living']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('library/index')
                ->where('movies.data.0.title', 'Night of the Living Dead')
                ->where('filters.q', 'living')
        );
});

test('sort and filter query params are echoed back as active filters', function () {
    fakeLibraryHttp();
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('library.index', ['sort' => 'year', 'dir' => 'desc', 'year' => 1968]))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'year')
                ->where('filters.dir', 'desc')
                ->where('filters.year', 1968)
                ->where('movies.data.0.title', 'Night of the Living Dead')
        );
});

test('a movie already watched is flagged in the search results', function () {
    fakeLibraryHttp();
    makeTorrentJob(['title' => 'Night of the Living Dead', 'last_watched_at' => now()]);
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('library.index', ['q' => 'living']))
        ->assertInertia(
            fn (AssertableInertia $page) => $page->where('movies.data.0.watched', true)
        );
});

test('a movie never watched is not flagged in the search results', function () {
    fakeLibraryHttp();
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('library.index', ['q' => 'living']))
        ->assertInertia(
            fn (AssertableInertia $page) => $page->where('movies.data.0.watched', false)
        );
});

test('an invalid sort value is rejected', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('library.index', ['sort' => 'not-a-real-sort']))
        ->assertSessionHasErrors('sort');
});

<?php

use App\Services\Search\WatchedMovieAnnotator;
use Illuminate\Pagination\LengthAwarePaginator;

function paginateMovies(array $movies): LengthAwarePaginator
{
    return new LengthAwarePaginator($movies, count($movies), 24, 1);
}

test('a movie matching a watched job title is flagged watched', function () {
    makeTorrentJob(['title' => 'Big Buck Bunny', 'last_watched_at' => now()]);
    $movies = paginateMovies([['title' => 'Big Buck Bunny']]);

    $annotated = app(WatchedMovieAnnotator::class)->annotate($movies);

    expect($annotated->items()[0]['watched'])->toBeTrue();
});

test('a movie with no matching watched job is not flagged watched', function () {
    $movies = paginateMovies([['title' => 'Never Seen This']]);

    $annotated = app(WatchedMovieAnnotator::class)->annotate($movies);

    expect($annotated->items()[0]['watched'])->toBeFalse();
});

test('a downloaded but never-watched job does not flag the movie as watched', function () {
    makeTorrentJob(['title' => 'Big Buck Bunny', 'status' => 'completed']);
    $movies = paginateMovies([['title' => 'Big Buck Bunny']]);

    $annotated = app(WatchedMovieAnnotator::class)->annotate($movies);

    expect($annotated->items()[0]['watched'])->toBeFalse();
});

test('matching is case-insensitive and ignores surrounding whitespace', function () {
    makeTorrentJob(['title' => '  BIG buck Bunny  ', 'last_watched_at' => now()]);
    $movies = paginateMovies([['title' => 'big BUCK bunny']]);

    $annotated = app(WatchedMovieAnnotator::class)->annotate($movies);

    expect($annotated->items()[0]['watched'])->toBeTrue();
});

test('a ping job (not a download) never counts as watched', function () {
    makeTorrentJob(['type' => 'ping', 'title' => 'Big Buck Bunny', 'last_watched_at' => now()]);
    $movies = paginateMovies([['title' => 'Big Buck Bunny']]);

    $annotated = app(WatchedMovieAnnotator::class)->annotate($movies);

    expect($annotated->items()[0]['watched'])->toBeFalse();
});

<?php

namespace App\Http\Controllers;

use App\Http\Requests\LibrarySearchRequest;
use App\Services\Search\MovieSearchCriteria;
use App\Services\Search\MovieSearchService;
use App\Services\Search\WatchedMovieAnnotator;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function __construct(
        private readonly MovieSearchService $movies,
        private readonly WatchedMovieAnnotator $watchedAnnotator,
    ) {}

    public function index(LibrarySearchRequest $request): Response
    {
        $criteria = MovieSearchCriteria::fromArray($request->validated());

        return Inertia::render('library/index', [
            'movies' => Inertia::scroll(
                fn () => $this->watchedAnnotator->annotate($this->movies->search($criteria))
            ),
            'filters' => [
                'q' => $criteria->query,
                'sort' => $criteria->sort,
                'dir' => $criteria->direction,
                'genre' => $criteria->genre,
                'min_rating' => $criteria->minRating,
                'year' => $criteria->year,
            ],
        ]);
    }
}

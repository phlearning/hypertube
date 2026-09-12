import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LibraryController from '@/actions/App/Http/Controllers/LibraryController';
import LibraryDownloadController from '@/actions/App/Http/Controllers/LibraryDownloadController';
import { index } from '@/routes/library';
import { Film, Star } from 'lucide-react';
import { Form, Head, InfiniteScroll, Link } from '@inertiajs/react';
import { Fragment } from 'react';

type TorrentCandidate = {
    source: string;
    source_id: string;
    torrent_url: string;
};

type Movie = {
    title: string;
    year: number | null;
    candidates: TorrentCandidate[];
    popularity: number;
    rating: number | null;
    poster: string | null;
    genre: string | null;
};

type Filters = {
    q: string | null;
    sort: string;
    dir: string;
    genre: string | null;
    min_rating: number | null;
    year: number | null;
};

type LibraryIndexProps = {
    movies: {
        data: Movie[];
    };
    filters: Filters;
};

function MovieCard({ movie }: { movie: Movie }) {
    return (
        <div className="flex flex-col overflow-hidden rounded-xl border bg-card">
            <div className="flex aspect-2/3 items-center justify-center bg-muted">
                {movie.poster ? (
                    <img
                        src={movie.poster}
                        alt={movie.title}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <Film className="size-10 text-muted-foreground" />
                )}
            </div>

            <div className="flex flex-1 flex-col gap-1 p-3">
                <span className="line-clamp-2 text-sm font-medium">
                    {movie.title}
                </span>

                <div className="mt-auto flex items-center justify-between text-xs text-muted-foreground">
                    <span>{movie.year ?? '—'}</span>

                    {movie.rating !== null && (
                        <span className="flex items-center gap-1">
                            <Star className="size-3 fill-current" />
                            {movie.rating.toFixed(1)}
                        </span>
                    )}
                </div>

                <Form
                    {...LibraryDownloadController.store.form()}
                    options={{ preserveScroll: true }}
                    className="mt-2"
                >
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="title" value={movie.title} />
                            {movie.candidates.map((candidate, i) => (
                                <Fragment key={`${candidate.source}:${candidate.source_id}`}>
                                    <input
                                        type="hidden"
                                        name={`candidates[${i}][source]`}
                                        value={candidate.source}
                                    />
                                    <input
                                        type="hidden"
                                        name={`candidates[${i}][source_id]`}
                                        value={candidate.source_id}
                                    />
                                    <input
                                        type="hidden"
                                        name={`candidates[${i}][torrent_url]`}
                                        value={candidate.torrent_url}
                                    />
                                </Fragment>
                            ))}
                            <Button
                                type="submit"
                                size="sm"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing ? 'Démarrage…' : 'Regarder'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}

const SORT_OPTIONS = [
    { value: 'popularity', label: 'Popularity' },
    { value: 'name', label: 'Name' },
    { value: 'year', label: 'Year' },
    { value: 'rating', label: 'Rating' },
];

export default function LibraryIndex({ movies, filters }: LibraryIndexProps) {
    return (
        <>
            <Head title="Library" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Library"
                        description="Search for a movie across our legal sources"
                    />

                    <Form
                        {...LibraryController.index.form()}
                        className="flex flex-wrap items-start gap-2"
                        options={{
                            preserveScroll: true,
                            replace: true,
                        }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="min-w-0 flex-1">
                                    <Label htmlFor="q" className="sr-only">
                                        Title
                                    </Label>
                                    <Input
                                        id="q"
                                        type="search"
                                        name="q"
                                        defaultValue={filters.q ?? ''}
                                        placeholder="Search by title..."
                                        aria-invalid={Boolean(errors.q)}
                                        autoComplete="off"
                                        maxLength={200}
                                    />
                                    <InputError className="mt-2" message={errors.q} />
                                </div>

                                <div>
                                    <Label htmlFor="sort" className="sr-only">
                                        Sort by
                                    </Label>
                                    <select
                                        id="sort"
                                        name="sort"
                                        defaultValue={filters.sort}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                    >
                                        {SORT_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <Label htmlFor="dir" className="sr-only">
                                        Direction
                                    </Label>
                                    <select
                                        id="dir"
                                        name="dir"
                                        defaultValue={filters.dir}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                    >
                                        <option value="asc">Ascending</option>
                                        <option value="desc">Descending</option>
                                    </select>
                                </div>

                                <div className="w-32">
                                    <Label htmlFor="genre" className="sr-only">
                                        Genre
                                    </Label>
                                    <Input
                                        id="genre"
                                        type="text"
                                        name="genre"
                                        defaultValue={filters.genre ?? ''}
                                        placeholder="Genre"
                                        aria-invalid={Boolean(errors.genre)}
                                        maxLength={100}
                                    />
                                    <InputError className="mt-2" message={errors.genre} />
                                </div>

                                <div className="w-24">
                                    <Label htmlFor="min_rating" className="sr-only">
                                        Min rating
                                    </Label>
                                    <Input
                                        id="min_rating"
                                        type="number"
                                        name="min_rating"
                                        min={0}
                                        max={10}
                                        step={0.1}
                                        defaultValue={filters.min_rating ?? ''}
                                        placeholder="Min ★"
                                        aria-invalid={Boolean(errors.min_rating)}
                                    />
                                    <InputError className="mt-2" message={errors.min_rating} />
                                </div>

                                <div className="w-24">
                                    <Label htmlFor="year" className="sr-only">
                                        Year
                                    </Label>
                                    <Input
                                        id="year"
                                        type="number"
                                        name="year"
                                        min={1888}
                                        max={new Date().getFullYear() + 1}
                                        defaultValue={filters.year ?? ''}
                                        placeholder="Year"
                                        aria-invalid={Boolean(errors.year)}
                                    />
                                    <InputError className="mt-2" message={errors.year} />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Searching…' : 'Search'}
                                </Button>
                            </>
                        )}
                    </Form>

                    {(filters.q != null ||
                        filters.genre != null ||
                        filters.min_rating != null ||
                        filters.year != null) && (
                        <Button variant="outline" asChild>
                            <Link href={index()} replace>
                                Clear filters
                            </Link>
                        </Button>
                    )}
                </div>

                {movies.data.length > 0 ? (
                    <InfiniteScroll data="movies" buffer={300} onlyNext>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                            {movies.data.map((movie) => (
                                <MovieCard
                                    key={movie.candidates
                                        .map((candidate) => `${candidate.source}:${candidate.source_id}`)
                                        .join(',')}
                                    movie={movie}
                                />
                            ))}
                        </div>
                    </InfiniteScroll>
                ) : (
                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {filters.q
                            ? `No movie found for "${filters.q}".`
                            : 'No movie available.'}
                    </div>
                )}
            </div>
        </>
    );
}

LibraryIndex.layout = {
    breadcrumbs: [
        {
            title: 'Library',
            href: index(),
        },
    ],
};

<?php

use App\Models\TorrentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function bstr(string $value): string
{
    return strlen($value).':'.$value;
}

function bint(int $value): string
{
    return 'i'.$value.'e';
}

function makeTorrentJob(array $overrides = []): TorrentJob
{
    return TorrentJob::create(array_merge([
        'job_id' => (string) Str::uuid(),
        'type' => 'download',
        'title' => 'Movie',
        'status' => 'queued',
        'torrent_url' => 'http://source.test/movie.torrent',
        'info_hash' => Str::random(40),
        'remaining_candidates' => [],
    ], $overrides));
}

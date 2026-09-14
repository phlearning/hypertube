<?php

use App\Services\Search\ArchiveOrgSource;
use Illuminate\Support\Facades\Http;

test('it normalizes archive.org search results', function () {
    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response([
            'response' => [
                'docs' => [
                    ['identifier' => 'NightOfTheLivingDead', 'title' => 'Night of the Living Dead', 'year' => 1968, 'downloads' => 500000],
                    ['identifier' => 'no-title-entry'],
                ],
            ],
        ]),
    ]);

    $results = (new ArchiveOrgSource)->search('night of the living dead');

    expect($results)->toHaveCount(1)
        ->and($results[0])->toMatchArray([
            'title' => 'Night of the Living Dead',
            'year' => 1968,
            'source' => 'archive_org',
            'source_id' => 'NightOfTheLivingDead',
            'torrent_url' => 'https://archive.org/download/NightOfTheLivingDead/NightOfTheLivingDead_archive.torrent',
            'popularity' => 500000,
        ]);
});

test('it excludes ROM/software preservation catalogs mistagged as movies on archive.org', function () {
    // archive.org's own mediatype:movies tag is unreliable: this exact item
    // (tosec_dat_19-2012-12-28) is tagged "movies" and even carries
    // auto-generated preview video formats (Motion JPEG, h.264), so
    // filtering by mediatype or format alone can't tell it apart from a
    // real film — only its title gives it away.
    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response([
            'response' => [
                'docs' => [
                    ['identifier' => 'NightOfTheLivingDead', 'title' => 'Night of the Living Dead', 'year' => 1968, 'downloads' => 500000],
                    ['identifier' => 'tosec_dat_19-2012-12-28', 'title' => 'TOSEC - DAT Pack - Complete (2001) (TOSEC-v2012-12-28)', 'downloads' => 900000],
                    ['identifier' => 'no-intro-set', 'title' => 'No-Intro Complete ROM Set (2020)', 'downloads' => 100],
                ],
            ],
        ]),
    ]);

    $results = (new ArchiveOrgSource)->search(null);

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Night of the Living Dead');
});

test('it returns an empty array when the request fails', function () {
    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response(null, 500),
    ]);

    expect((new ArchiveOrgSource)->search('anything'))->toBe([]);
});

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

test('it returns an empty array when the request fails', function () {
    Http::fake([
        'archive.org/advancedsearch.php*' => Http::response(null, 500),
    ]);

    expect((new ArchiveOrgSource)->search('anything'))->toBe([]);
});

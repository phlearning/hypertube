<?php

use App\Services\Search\PublicDomainTorrentsSource;
use Illuminate\Support\Facades\Http;

function sampleListingHtml(): string
{
    return <<<'HTML'
    <html><body>
    <a href="nshowmovie.html?movieid=100">Night of the Living Dead</a>
    <a href="nshowmovie.html?movieid=101">His Girl Friday</a>
    <a href="notamovielink.html">Ignore Me</a>
    </body></html>
    HTML;
}

test('it parses the category listing into normalized movies', function () {
    Http::fake([
        'publicdomaintorrents.info/nshowcat.html*' => Http::response(sampleListingHtml()),
    ]);

    $results = (new PublicDomainTorrentsSource)->search(null);

    expect($results)->toHaveCount(2)
        ->and($results[0])->toMatchArray([
            'title' => 'Night of the Living Dead',
            'year' => null,
            'source' => 'public_domain_torrents',
            'source_id' => '100',
        ]);
});

test('it filters the listing by title when a query is given', function () {
    Http::fake([
        'publicdomaintorrents.info/nshowcat.html*' => Http::response(sampleListingHtml()),
    ]);

    $results = (new PublicDomainTorrentsSource)->search('his girl');

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('His Girl Friday');
});

test('it returns an empty array when the request fails', function () {
    Http::fake([
        'publicdomaintorrents.info/nshowcat.html*' => Http::response(null, 500),
    ]);

    expect((new PublicDomainTorrentsSource)->search(null))->toBe([]);
});

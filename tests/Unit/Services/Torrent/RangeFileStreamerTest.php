<?php

use App\Services\Torrent\RangeFileStreamer;
use Symfony\Component\HttpFoundation\Response;

function streamedBody(Response $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

function fixtureFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'range_streamer_test_');
    file_put_contents($path, $content);

    return $path;
}

test('with no Range header, it serves the available bytes as a full 200 response', function () {
    $path = fixtureFile(str_repeat('a', 40).str_repeat('z', 60));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: null);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Length'))->toBe('40')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and(streamedBody($response))->toBe(str_repeat('a', 40));

    unlink($path);
});

test('with no Range header and zero available bytes, it serves an empty 200 response', function () {
    $path = fixtureFile(str_repeat('z', 100));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 0, totalBytes: 100, rangeHeader: null);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Length'))->toBe('0')
        ->and(streamedBody($response))->toBe('');

    unlink($path);
});

test('a Range request fully inside the available bytes is served as 206 Partial Content', function () {
    $path = fixtureFile(str_repeat('a', 100));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: 'bytes=0-9');

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Range'))->toBe('bytes 0-9/100')
        ->and($response->headers->get('Content-Length'))->toBe('10')
        ->and(streamedBody($response))->toBe(str_repeat('a', 10));

    unlink($path);
});

test('an open-ended Range request is clamped to availableBytes, never leaking undownloaded bytes', function () {
    $content = str_repeat('a', 40).str_repeat('z', 60);
    $path = fixtureFile($content);

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: 'bytes=30-');

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Range'))->toBe('bytes 30-39/100')
        ->and($response->headers->get('Content-Length'))->toBe('10')
        ->and(streamedBody($response))->toBe(str_repeat('a', 10));

    unlink($path);
});

test('a Range request whose end exceeds availableBytes is clamped, not refused', function () {
    $path = fixtureFile(str_repeat('a', 100));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: 'bytes=0-99');

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Range'))->toBe('bytes 0-39/100')
        ->and($response->headers->get('Content-Length'))->toBe('40');

    unlink($path);
});

test('a Range request starting beyond availableBytes is rejected with 416, never served', function () {
    $path = fixtureFile(str_repeat('a', 100));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: 'bytes=50-60');

    expect($response->getStatusCode())->toBe(416)
        ->and($response->headers->get('Content-Range'))->toBe('bytes */100');

    unlink($path);
});

test('a Range request when nothing is available yet is rejected with 416', function () {
    $path = fixtureFile(str_repeat('a', 100));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 0, totalBytes: 100, rangeHeader: 'bytes=0-10');

    expect($response->getStatusCode())->toBe(416);

    unlink($path);
});

test('a suffix Range request serves the last N available bytes', function () {
    $content = str_repeat('a', 30).'0123456789';
    $path = fixtureFile($content.str_repeat('z', 60));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: 'bytes=-10');

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Range'))->toBe('bytes 30-39/100')
        ->and(streamedBody($response))->toBe('0123456789');

    unlink($path);
});

test('a malformed Range header is ignored, falling back to a full 200 response', function () {
    $path = fixtureFile(str_repeat('a', 40).str_repeat('z', 60));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: 'not-a-range');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Length'))->toBe('40');

    unlink($path);
});

test('a missing file produces an empty body instead of a fatal error', function () {
    $path = sys_get_temp_dir().'/range_streamer_test_does_not_exist_'.uniqid();

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: 100, rangeHeader: null);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Length'))->toBe('40')
        ->and(streamedBody($response))->toBe('');
});

test('when totalBytes is unknown, the Content-Range total is reported as *', function () {
    $path = fixtureFile(str_repeat('a', 100));

    $response = (new RangeFileStreamer)->stream($path, availableBytes: 40, totalBytes: null, rangeHeader: 'bytes=50-60');

    expect($response->getStatusCode())->toBe(416)
        ->and($response->headers->get('Content-Range'))->toBe('bytes */*');

    unlink($path);
});

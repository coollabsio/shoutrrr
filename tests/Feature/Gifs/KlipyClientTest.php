<?php

use App\Services\Gifs\KlipyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function klipyPayload(bool $hasNext = false): array
{
    return [
        'result' => true,
        'data' => [
            'current_page' => 1,
            'has_next' => $hasNext,
            'data' => [
                [
                    'id' => 991,
                    'slug' => 'happy-dance-991',
                    'title' => 'Happy dance',
                    'file' => [
                        'sm' => ['gif' => ['url' => 'https://cdn.klipy.com/sm.gif', 'width' => 120, 'height' => 90, 'size' => 40000]],
                        'md' => ['gif' => ['url' => 'https://cdn.klipy.com/md.gif', 'width' => 320, 'height' => 240, 'size' => 900000]],
                        'hd' => ['gif' => ['url' => 'https://cdn.klipy.com/hd.gif', 'width' => 640, 'height' => 480, 'size' => 4000000]],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Clips come back in a different shape from gifs and stickers: `file` maps a
 * format straight to a URL string (no quality tiers, no nested object), and the
 * dimensions live in a sibling `file_meta`. Captured verbatim from a live
 * `clips/trending` response on 2026-07-27.
 */
function klipyClipPayload(): array
{
    return [
        'result' => true,
        'data' => [
            'current_page' => 1,
            'has_next' => true,
            'data' => [
                [
                    'url' => 'https://klipy.com/clips/baby-sloth-yawn',
                    'title' => 'Baby sloth yawn',
                    'slug' => 'baby-sloth-yawn',
                    'file' => [
                        'mp4' => 'https://static.klipy.com/ii/7e6c/StVDZJwq.mp4',
                        'gif' => 'https://static.klipy.com/ii/7e6c/BWnCJhPb.gif',
                        'webp' => 'https://static.klipy.com/ii/7e6c/lmvoKnwc.webp',
                    ],
                    'file_meta' => [
                        'mp4' => ['width' => 854, 'height' => 480, 'size' => 339973],
                        'gif' => ['width' => 320, 'height' => 180, 'size' => 2719596],
                        'webp' => ['width' => 320, 'height' => 180, 'size' => 380542],
                    ],
                    'tags' => [],
                    'type' => 'clip',
                ],
            ],
        ],
    ];
}

beforeEach(function (): void {
    config()->set('services.klipy.key', 'test-key');
    config()->set('services.klipy.rating', 'pg-13');
});

test('reports whether it is configured', function () {
    expect(app(KlipyClient::class)->configured())->toBeTrue();

    config()->set('services.klipy.key', null);

    expect(app(KlipyClient::class)->configured())->toBeFalse();
});

test('browses trending when no query is given', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(klipyPayload())]);

    $result = app(KlipyClient::class)->browse('gif', null, 1);

    expect($result['has_next'])->toBeFalse()
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]->slug)->toBe('happy-dance-991')
        ->and($result['items'][0]->title)->toBe('Happy dance');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/test-key/gifs/trending')
        && $request['rating'] === 'pg-13'
        && $request['page'] === '1');
});

test('browses search when a query is given', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(klipyPayload(true))]);

    $result = app(KlipyClient::class)->browse('gif', 'thanks', 2);

    expect($result['has_next'])->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/test-key/gifs/search')
        && $request['q'] === 'thanks'
        && $request['page'] === '2');
});

test('orders variants largest to smallest and picks the smallest as preview', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(klipyPayload())]);

    $item = app(KlipyClient::class)->browse('gif', null, 1)['items'][0];

    expect(array_map(fn ($v) => $v->bytes, $item->variants))->toBe([4000000, 900000, 40000])
        ->and($item->preview->url)->toBe('https://cdn.klipy.com/sm.gif');
});

test('never sends ad or customer parameters when browsing', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(klipyPayload())]);

    app(KlipyClient::class)->browse('gif', 'thanks', 1);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'customer_id')
        && ! str_contains($request->url(), 'ad-'));
});

test('sends the customer id when fetching recents', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(klipyPayload())]);

    app(KlipyClient::class)->recent('gif', 'cust-abc', 1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/gifs/recent/cust-abc'));
});

test('throws without leaking the api key when klipy errors', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response('nope', 500)]);

    expect(fn () => app(KlipyClient::class)->browse('gif', null, 1))
        ->toThrow(fn (RuntimeException $e) => expect($e->getMessage())->not->toContain('test-key'));
});

test('rejects an unknown catalog', function () {
    expect(fn () => app(KlipyClient::class)->browse('memes', null, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('throws without leaking the api key when the connection itself fails', function () {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 6: Could not resolve host: api.klipy.com (see https://api.klipy.com/api/v1/test-key/gifs/trending)'
    ));

    expect(fn () => app(KlipyClient::class)->browse('gif', null, 1))
        ->toThrow(fn (RuntimeException $e) => expect($e->getMessage())->not->toContain('test-key'));
});

test('swallows a connection failure when sharing', function () {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 6: Could not resolve host: api.klipy.com (see https://api.klipy.com/api/v1/test-key/gifs/share)'
    ));

    app(KlipyClient::class)->share('gif', 'happy-dance-991', 'cust-abc');
})->throwsNoExceptions();

test('normalizes a clip from klipy\'s flat file map and sibling file_meta', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(klipyClipPayload())]);

    $result = app(KlipyClient::class)->browse('clip', null, 1);

    expect($result['items'])->toHaveCount(1);

    $clip = $result['items'][0];

    // Clips carry no `id`, so the slug has to stand in for it.
    expect($clip->id)->toBe('baby-sloth-yawn')
        ->and($clip->slug)->toBe('baby-sloth-yawn')
        ->and($clip->catalog)->toBe('clip')
        ->and($clip->title)->toBe('Baby sloth yawn')
        ->and($clip->variants)->toHaveCount(1);

    $mp4 = $clip->variants[0];

    expect($mp4->url)->toBe('https://static.klipy.com/ii/7e6c/StVDZJwq.mp4')
        ->and($mp4->mime)->toBe('video/mp4')
        // Dimensions must come from file_meta — the flat map has no room for them.
        ->and($mp4->width)->toBe(854)
        ->and($mp4->height)->toBe(480)
        ->and($mp4->bytes)->toBe(339973);
});

test('drops a clip that offers no mp4', function () {
    $payload = klipyClipPayload();
    unset($payload['data']['data'][0]['file']['mp4'], $payload['data']['data'][0]['file_meta']['mp4']);

    Http::fake(['https://api.klipy.com/*' => Http::response($payload)]);

    expect(app(KlipyClient::class)->browse('clip', null, 1)['items'])->toBe([]);
});

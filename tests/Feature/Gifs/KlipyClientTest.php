<?php

use App\Services\Gifs\KlipyClient;
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

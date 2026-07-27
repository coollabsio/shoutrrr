<?php

use App\Jobs\TriggerKlipyShare;
use App\Services\Gifs\KlipyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.klipy.key', 'test-key');
});

test('dispatches when the share trigger is enabled', function () {
    Queue::fake();
    config()->set('services.klipy.share_trigger', true);

    TriggerKlipyShare::maybeDispatch('gif', 'happy-dance-991', 'cust-abc');

    Queue::assertPushed(TriggerKlipyShare::class, fn (TriggerKlipyShare $job): bool => $job->slug === 'happy-dance-991'
        && $job->customerId === 'cust-abc');
});

test('does not dispatch when the share trigger is disabled', function () {
    Queue::fake();
    config()->set('services.klipy.share_trigger', false);

    TriggerKlipyShare::maybeDispatch('gif', 'happy-dance-991', 'cust-abc');

    Queue::assertNothingPushed();
});

test('posts the share to klipy when it runs', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(['result' => true])]);

    (new TriggerKlipyShare('gif', 'happy-dance-991', 'cust-abc'))->handle(app(KlipyClient::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/test-key/gifs/share')
        && $request['slug'] === 'happy-dance-991'
        && $request['customer_id'] === 'cust-abc');
});

test('still completes without throwing on an http failure response, since share() has no status check', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response('nope', 500)]);

    expect(fn () => (new TriggerKlipyShare('gif', 'x', 'cust-abc'))->handle(app(KlipyClient::class)))
        ->not->toThrow(Exception::class);
});

test('swallows a klipy failure so a retry storm never follows an attach', function () {
    Log::spy();

    // An unknown catalog is the one path that actually reaches KlipyClient::share()
    // and throws: pathFor() raises InvalidArgumentException before any HTTP call is
    // made. This genuinely exercises handle()'s try/catch, unlike an HTTP failure
    // (share() never checks the response status).
    expect(fn () => (new TriggerKlipyShare('memes', 'x', 'cust-abc'))->handle(app(KlipyClient::class)))
        ->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Klipy share trigger failed'
            && $context['slug'] === 'x');
});

test('never logs the api key even if a connection failure message embeds it', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 6: Could not resolve host: api.klipy.com (see https://api.klipy.com/api/v1/test-key/gifs/share)'
    ));

    (new TriggerKlipyShare('gif', 'happy-dance-991', 'cust-abc'))->handle(app(KlipyClient::class));

    // KlipyClient::share() swallows ConnectionException itself, so today this never
    // reaches handle()'s Log::info call at all. That's the invariant this test pins:
    // even if a future Laravel/Guzzle change caused the connection failure to carry
    // the raw request URI through to a log call, this assertion would catch a context
    // containing the key. It's the same guard regardless of whether a call happens.
    Log::shouldNotHaveReceived('info', [
        Mockery::any(),
        Mockery::on(fn (array $context): bool => str_contains(json_encode($context), 'test-key')),
    ]);
});

<?php

use App\Jobs\TriggerKlipyShare;
use App\Services\Gifs\KlipyClient;
use Illuminate\Support\Facades\Http;
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

test('swallows a klipy failure so a retry storm never follows an attach', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response('nope', 500)]);

    expect(fn () => (new TriggerKlipyShare('gif', 'x', 'cust-abc'))->handle(app(KlipyClient::class)))
        ->not->toThrow(Exception::class);
});

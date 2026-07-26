<?php

use App\Http\Middleware\EnsureMessagesEnabled;
use App\Support\InstanceSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('middleware 404s when disabled and passes when enabled', function () {
    $mw = new EnsureMessagesEnabled;
    $next = fn ($req) => response('ok');

    config(['messages.enabled' => true]);
    expect($mw->handle(Request::create('/messages'), $next)->getContent())->toBe('ok');

    config(['messages.enabled' => false]);
    expect(fn () => $mw->handle(Request::create('/messages'), $next))
        ->toThrow(NotFoundHttpException::class);
});

test('a persisted instance-settings override takes precedence over the config default', function () {
    $mw = new EnsureMessagesEnabled;
    $next = fn ($req) => response('ok');

    // Config says on (the shipped default), but the instance owner has flipped
    // the runtime toggle off from Settings → Instance → Polling.
    config(['messages.enabled' => true]);
    app(InstanceSettings::class)->update(['messages_enabled' => false]);

    expect(fn () => $mw->handle(Request::create('/messages'), $next))
        ->toThrow(NotFoundHttpException::class);
});

<?php

use App\Http\Middleware\EnsureEngagementEnabled;
use App\Support\InstanceSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('middleware 404s when disabled and passes when enabled', function () {
    $mw = new EnsureEngagementEnabled;
    $next = fn ($req) => response('ok');

    config(['engagement.enabled' => true]);
    expect($mw->handle(Request::create('/engagement'), $next)->getContent())->toBe('ok');

    config(['engagement.enabled' => false]);
    expect(fn () => $mw->handle(Request::create('/engagement'), $next))
        ->toThrow(NotFoundHttpException::class);
});

test('a persisted instance-settings override takes precedence over the config default', function () {
    $mw = new EnsureEngagementEnabled;
    $next = fn ($req) => response('ok');

    // Config says on, but the instance owner has flipped the runtime toggle off.
    config(['engagement.enabled' => true]);
    app(InstanceSettings::class)->update(['engagement_enabled' => false]);

    expect(fn () => $mw->handle(Request::create('/engagement'), $next))
        ->toThrow(NotFoundHttpException::class);
});

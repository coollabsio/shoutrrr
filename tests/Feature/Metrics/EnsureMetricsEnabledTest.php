<?php

use App\Http\Middleware\EnsureMetricsEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('middleware 404s when disabled and passes when enabled', function () {
    $mw = new EnsureMetricsEnabled;
    $next = fn ($req) => response('ok');

    config(['metrics.enabled' => true]);
    expect($mw->handle(Request::create('/analytics'), $next)->getContent())->toBe('ok');

    config(['metrics.enabled' => false]);
    expect(fn () => $mw->handle(Request::create('/analytics'), $next))
        ->toThrow(NotFoundHttpException::class);
});

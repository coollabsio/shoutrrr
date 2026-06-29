<?php

use App\Http\Middleware\EnsureAiEnabled;
use App\Support\InstanceSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('blocks when ai unconfigured and passes when configured', function () {
    $mw = app(EnsureAiEnabled::class);
    $next = fn ($r) => response('ok');

    expect(fn () => $mw->handle(Request::create('/x'), $next))
        ->toThrow(HttpException::class);

    app(InstanceSettings::class)->updateAi(
        ['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'],
        'sk-test',
    );

    expect($mw->handle(Request::create('/x'), $next)->getContent())->toBe('ok');
});

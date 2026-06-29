<?php

use App\Services\Ai\AiManager;
use App\Support\InstanceSettings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('aborts when ai is not configured', function () {
    expect(fn () => app(AiManager::class)->textRequest())
        ->toThrow(HttpException::class);
});

it('builds a working prism request when configured', function () {
    app(InstanceSettings::class)->updateAi([
        'ai_enabled' => true,
        'ai_provider' => 'anthropic',
        'ai_model' => 'claude-sonnet-4-5',
    ], 'sk-test');

    Prism::fake([
        TextResponseFake::make()->withText('hello world'),
    ]);

    $response = app(AiManager::class)->textRequest()
        ->withPrompt('hi')
        ->asText();

    expect($response->text)->toBe('hello world');
});

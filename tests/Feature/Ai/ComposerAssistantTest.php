<?php

use App\Services\Ai\ComposerAssistant;
use App\Support\InstanceSettings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

beforeEach(function () {
    app(InstanceSettings::class)->updateAi(
        ['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'],
        'sk-test',
    );
});

it('streams a rewrite as text deltas', function () {
    Prism::fake([TextResponseFake::make()->withText('Improved copy')]);

    $out = '';
    foreach (app(ComposerAssistant::class)->rewrite('old copy', 'x', 280) as $delta) {
        $out .= $delta;
    }

    expect($out)->toBe('Improved copy');
});

it('streams a preset transform', function () {
    Prism::fake([TextResponseFake::make()->withText('Short.')]);

    $out = '';
    foreach (app(ComposerAssistant::class)->preset('shorten', 'long text', 'x', 280) as $delta) {
        $out .= $delta;
    }

    expect($out)->toBe('Short.');
});

it('streams a generate response', function () {
    Prism::fake([TextResponseFake::make()->withText('Generated post.')]);

    $out = '';
    foreach (app(ComposerAssistant::class)->generate('write about AI', 'x', 280) as $delta) {
        $out .= $delta;
    }

    expect($out)->toBe('Generated post.');
});

it('streams an adapt response', function () {
    Prism::fake([TextResponseFake::make()->withText('Adapted for LinkedIn.')]);

    $out = '';
    foreach (app(ComposerAssistant::class)->adapt('some text', 'linkedin', 3000) as $delta) {
        $out .= $delta;
    }

    expect($out)->toBe('Adapted for LinkedIn.');
});

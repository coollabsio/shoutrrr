<?php

use App\Models\InstanceSetting;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Crypt;

it('reports unconfigured by default', function () {
    expect(app(InstanceSettings::class)->aiConfigured())->toBeFalse();
});

it('stores the api key encrypted and never returns it raw', function () {
    $settings = app(InstanceSettings::class);

    $settings->updateAi([
        'ai_enabled' => true,
        'ai_provider' => 'anthropic',
        'ai_model' => 'claude-sonnet-4-5',
    ], 'sk-secret-123');

    $stored = InstanceSetting::query()->whereKey('ai_api_key')->value('value');
    expect($stored)->not->toBe('sk-secret-123');
    expect(Crypt::decryptString($stored))->toBe('sk-secret-123');

    $fresh = app(InstanceSettings::class);
    expect($fresh->aiApiKey())->toBe('sk-secret-123');
    expect($fresh->aiConfigured())->toBeTrue();
    expect($fresh->aiSettings())->toMatchArray([
        'ai_enabled' => true,
        'ai_provider' => 'anthropic',
        'ai_model' => 'claude-sonnet-4-5',
        'ai_api_key_set' => true,
    ]);
});

it('leaves the key unchanged on null and clears it on empty string', function () {
    $settings = app(InstanceSettings::class);
    $settings->updateAi(['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'], 'sk-keep');

    app(InstanceSettings::class)->updateAi(['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'], null);
    expect(app(InstanceSettings::class)->aiApiKey())->toBe('sk-keep');

    app(InstanceSettings::class)->updateAi(['ai_enabled' => false, 'ai_provider' => 'anthropic', 'ai_model' => 'm'], '');
    expect(app(InstanceSettings::class)->aiApiKey())->toBeNull();
    expect(app(InstanceSettings::class)->aiConfigured())->toBeFalse();
});

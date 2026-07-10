<?php

use App\Enums\Platform;

test('discord is a launched, configured, webhook-only platform', function () {
    expect(Platform::Discord->label())->toBe('Discord')
        ->and(Platform::Discord->supportsOAuth())->toBeFalse()
        ->and(Platform::Discord->supportsAppPassword())->toBeFalse()
        ->and(Platform::Discord->supportsWebhook())->toBeTrue()
        ->and(Platform::Discord->isConfigured())->toBeTrue()
        ->and(Platform::Discord->isLaunched())->toBeTrue()
        ->and(Platform::Discord->socialiteDriver())->toBeNull()
        ->and(Platform::Discord->configKey())->toBeNull();
});

test('only discord supports the webhook connect flow', function () {
    foreach (Platform::cases() as $platform) {
        expect($platform->supportsWebhook())->toBe($platform === Platform::Discord);
    }
});

test('discord is the only platform without engagement support', function () {
    foreach (Platform::cases() as $platform) {
        expect($platform->supportsEngagement())->toBe($platform !== Platform::Discord);
    }
});

test('discord limits match the spec', function () {
    expect(Platform::Discord->maxLength())->toBe(2000)
        ->and(Platform::Discord->maxMedia())->toBe(10)
        ->and(Platform::Discord->maxMediaBytes())->toBe(8_388_608)
        ->and(Platform::Discord->threadMax())->toBeNull()
        ->and(Platform::Discord->measure('héllo'))->toBe(5);
});

test('capabilities exposes supportsWebhook for every platform', function () {
    $discord = collect(Platform::capabilities())->firstWhere('platform', 'discord');

    expect($discord)->not->toBeNull()
        ->and($discord['supportsWebhook'])->toBeTrue()
        ->and($discord['label'])->toBe('Discord');
});

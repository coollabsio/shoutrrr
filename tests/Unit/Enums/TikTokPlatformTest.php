<?php

use App\Enums\Platform;

test('tiktok connects through its own socialite driver with the content posting scopes', function () {
    expect(Platform::TikTok->label())->toBe('TikTok')
        ->and(Platform::TikTok->supportsOAuth())->toBeTrue()
        ->and(Platform::TikTok->socialiteDriver())->toBe('tiktok')
        ->and(Platform::TikTok->configKey())->toBe('services.tiktok')
        ->and(Platform::TikTok->usesMetaConnectionFlow())->toBeFalse()
        ->and(Platform::TikTok->scopes())->toBe(['user.info.basic', 'user.info.profile', 'video.publish', 'video.upload']);
});

test('tiktok is configured only with both the client key and secret', function () {
    config()->set('services.tiktok.client_id', 'key');
    config()->set('services.tiktok.client_secret', '');
    expect(Platform::TikTok->isConfigured())->toBeFalse();

    config()->set('services.tiktok.client_secret', 'secret');
    expect(Platform::TikTok->isConfigured())->toBeTrue();
});

test('tiktok limits follow the content posting api', function () {
    expect(Platform::TikTok->maxLength())->toBe(2200)
        ->and(Platform::TikTok->threadMax())->toBe(1)
        ->and(Platform::TikTok->maxMedia())->toBe(35)
        ->and(Platform::TikTok->requiresMedia())->toBeTrue()
        ->and(Platform::TikTok->allowedMime())->toBe(['image/jpeg', 'image/webp'])
        ->and(Platform::TikTok->supportsRepost())->toBeFalse();
});

test('tiktok counts caption length in utf-16 code units', function () {
    expect(Platform::TikTok->measure('hi 👋'))->toBe(5);
});

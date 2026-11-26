<?php

use App\Enums\Platform;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;

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
        ->and(Platform::TikTok->fetchedImageFrame())->toBe(['long_edge' => 1920, 'short_edge' => 1080])
        ->and(Platform::TikTok->supportsRepost())->toBeFalse();
});

test('tiktok counts caption length in utf-16 code units', function () {
    expect(Platform::TikTok->measure('hi 👋'))->toBe(5);
});

test('photo posts use the post mode names the content endpoint expects', function () {
    expect(TikTokPostMode::Direct->photoPostMode())->toBe('DIRECT_POST')
        ->and(TikTokPostMode::Inbox->photoPostMode())->toBe('MEDIA_UPLOAD');
});

test('privacy levels carry the labels the composer shows', function () {
    expect(array_map(fn (TikTokPrivacyLevel $level): array => [$level->value, $level->label()], TikTokPrivacyLevel::cases()))
        ->toBe([
            ['PUBLIC_TO_EVERYONE', 'Everyone'],
            ['MUTUAL_FOLLOW_FRIENDS', 'Friends'],
            ['FOLLOWER_OF_CREATOR', 'Followers'],
            ['SELF_ONLY', 'Only me'],
        ]);
});

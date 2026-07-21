<?php

use App\Enums\Platform;
use App\Enums\PostFormat;

test('story is the only format that drops the caption', function () {
    expect(PostFormat::Feed->allowsCaption())->toBeTrue();
    expect(PostFormat::Reels->allowsCaption())->toBeTrue();
    expect(PostFormat::Story->allowsCaption())->toBeFalse();
});

test('reels requires a video; story and reels require media', function () {
    expect(PostFormat::Reels->requiresVideo())->toBeTrue();
    expect(PostFormat::Feed->requiresVideo())->toBeFalse();
    expect(PostFormat::Story->requiresVideo())->toBeFalse();

    expect(PostFormat::Reels->requiresMedia())->toBeTrue();
    expect(PostFormat::Story->requiresMedia())->toBeTrue();
    expect(PostFormat::Feed->requiresMedia())->toBeFalse();
});

test('reels and story are single-media only', function () {
    expect(PostFormat::Reels->singleMediaOnly())->toBeTrue();
    expect(PostFormat::Story->singleMediaOnly())->toBeTrue();
    expect(PostFormat::Feed->singleMediaOnly())->toBeFalse();
});

test('only instagram and facebook offer non-feed formats', function () {
    expect(PostFormat::forPlatform(Platform::Instagram))
        ->toBe([PostFormat::Feed, PostFormat::Reels, PostFormat::Story]);
    expect(PostFormat::forPlatform(Platform::Facebook))
        ->toBe([PostFormat::Feed, PostFormat::Reels, PostFormat::Story]);
    expect(PostFormat::forPlatform(Platform::X))->toBe([PostFormat::Feed]);
    expect(PostFormat::forPlatform(Platform::LinkedIn))->toBe([PostFormat::Feed]);
});

<?php

use App\Enums\Platform;

test('platform exposes the correct primary length limit', function () {
    expect(Platform::X->maxLength())->toBe(280)
        ->and(Platform::Bluesky->maxLength())->toBe(300)
        ->and(Platform::LinkedIn->maxLength())->toBe(3000);
});

test('only bluesky carries a byte limit', function () {
    expect(Platform::Bluesky->maxBytes())->toBe(3000)
        ->and(Platform::X->maxBytes())->toBeNull()
        ->and(Platform::LinkedIn->maxBytes())->toBeNull();
});

test('only linkedin caps the thread length', function () {
    expect(Platform::LinkedIn->threadMax())->toBe(1)
        ->and(Platform::X->threadMax())->toBeNull()
        ->and(Platform::Bluesky->threadMax())->toBeNull();
});

test('media constraints match each platform', function () {
    expect(Platform::X->maxMedia())->toBe(4)
        ->and(Platform::LinkedIn->maxMedia())->toBe(9)
        ->and(Platform::Bluesky->allowedMime())->toContain('image/webp')
        ->and(Platform::LinkedIn->allowedMime())->toContain('image/gif');
});

test('measure counts plain ascii uniformly across platforms', function () {
    expect(Platform::X->measure('hello'))->toBe(5)
        ->and(Platform::Bluesky->measure('hello'))->toBe(5)
        ->and(Platform::LinkedIn->measure('hello'))->toBe(5);
});

test('the limits array exposes one descriptor per platform for the frontend', function () {
    $limits = Platform::allLimits();

    expect($limits)->toHaveCount(3)
        ->and($limits[0])->toHaveKeys(['platform', 'maxLength', 'maxBytes', 'maxMedia', 'maxMediaBytes', 'allowedMime', 'threadMax']);
});

<?php

use App\Enums\Platform;

test('per-platform video limits match the spec', function (): void {
    expect(Platform::X->maxVideoBytes())->toBe(536_870_912)
        ->and(Platform::X->maxVideoDurationSeconds())->toBe(140)
        ->and(Platform::LinkedIn->maxVideoBytes())->toBe(524_288_000)
        ->and(Platform::LinkedIn->maxVideoDurationSeconds())->toBe(1800)
        ->and(Platform::Bluesky->maxVideoBytes())->toBe(104_857_600)
        ->and(Platform::Bluesky->maxVideoDurationSeconds())->toBe(180)
        ->and(Platform::X->allowedVideoMime())->toBe(['video/mp4']);
});

test('video byte ceiling is the largest per-platform cap', function (): void {
    expect(Platform::maxVideoBytesCeiling())->toBe(536_870_912);
});

test('limits payload exposes video fields', function (): void {
    expect(Platform::X->limits())
        ->toHaveKeys(['allowedVideoMime', 'maxVideoBytes', 'maxVideoDurationSeconds']);
});

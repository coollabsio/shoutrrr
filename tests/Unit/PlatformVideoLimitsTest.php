<?php

use App\Enums\Platform;

test('per-platform video limits match the spec', function (): void {
    expect(Platform::X->maxVideoBytes())->toBe(536_870_912)
        ->and(Platform::X->maxVideoDurationSeconds())->toBe(140)
        ->and(Platform::LinkedIn->maxVideoBytes())->toBe(524_288_000)
        ->and(Platform::LinkedIn->maxVideoDurationSeconds())->toBe(1800)
        ->and(Platform::Bluesky->maxVideoBytes())->toBe(100_000_000)
        ->and(Platform::Bluesky->maxVideoDurationSeconds())->toBe(180)
        ->and(Platform::X->allowedVideoMime())->toBe(['video/mp4']);
});

test('video byte ceiling is the largest per-platform cap', function (): void {
    expect(Platform::maxVideoBytesCeiling())->toBe(1_073_741_824);
});

test('limits payload exposes video fields', function (): void {
    expect(Platform::X->limits())
        ->toHaveKeys(['allowedVideoMime', 'maxVideoBytes', 'maxVideoDurationSeconds']);
});

test('meta platform video limits are set', function (): void {
    expect(Platform::Facebook->maxVideoBytes())->toBe(1_073_741_824)
        ->and(Platform::Facebook->maxVideoDurationSeconds())->toBe(1200)
        ->and(Platform::Instagram->maxVideoBytes())->toBe(1_073_741_824)
        ->and(Platform::Instagram->maxVideoDurationSeconds())->toBe(900)
        ->and(Platform::Threads->maxVideoBytes())->toBe(1_073_741_824)
        ->and(Platform::Threads->maxVideoDurationSeconds())->toBe(300);
});

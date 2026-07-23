<?php

use App\Enums\Platform;

test('post metrics is supported by every platform', function () {
    foreach (Platform::cases() as $platform) {
        expect($platform->supportsPostMetrics())->toBeTrue();
    }
});

test('account metrics is supported by every platform except Discord', function () {
    foreach (Platform::cases() as $platform) {
        expect($platform->supportsAccountMetrics())->toBe($platform !== Platform::Discord);
    }
});

test('pollingSectionPlatforms returns the capability matrix per section', function () {
    $values = fn (string $section): array => array_map(
        fn (Platform $p): string => $p->value,
        Platform::pollingSectionPlatforms($section),
    );

    expect($values('engagement'))->toBe(['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads'])
        ->and($values('post_metrics'))->toBe(['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads', 'discord'])
        ->and($values('account_metrics'))->toBe(['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads']);
});

test('pollingSectionPlatforms is empty for an unknown section', function () {
    expect(Platform::pollingSectionPlatforms('nonsense'))->toBe([]);
});

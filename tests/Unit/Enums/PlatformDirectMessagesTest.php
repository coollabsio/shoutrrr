<?php

use App\Enums\Platform;

test('dm platforms are exactly x, bluesky, instagram, facebook', function () {
    expect(Platform::X->supportsDirectMessages())->toBeTrue();
    expect(Platform::Bluesky->supportsDirectMessages())->toBeTrue();
    expect(Platform::Instagram->supportsDirectMessages())->toBeTrue();
    expect(Platform::Facebook->supportsDirectMessages())->toBeTrue();
    expect(Platform::Threads->supportsDirectMessages())->toBeFalse();
    expect(Platform::LinkedIn->supportsDirectMessages())->toBeFalse();
    expect(Platform::Discord->supportsDirectMessages())->toBeFalse();
});

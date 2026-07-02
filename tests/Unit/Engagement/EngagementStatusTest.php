<?php

use App\Enums\EngagementStatus;
use App\Enums\ReplyStatus;

test('engagement status reports ok only for ok', function () {
    expect(EngagementStatus::Ok->isOk())->toBeTrue();
    expect(EngagementStatus::Failed->isOk())->toBeFalse();
    expect(EngagementStatus::Unsupported->isOk())->toBeFalse();
});

test('reply status has the three lifecycle cases', function () {
    expect(array_map(fn (ReplyStatus $s) => $s->value, ReplyStatus::cases()))
        ->toBe(['pending', 'responded', 'archived']);
});

test('engagement config exposes enabled flag and window', function () {
    expect(config('engagement.enabled'))->toBeTrue();
    expect(config('engagement.window_days'))->toBe(7);
});

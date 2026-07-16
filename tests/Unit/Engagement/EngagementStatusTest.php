<?php

use App\Enums\EngagementStatus;
use App\Enums\ReplyStatus;

test('engagement status reports ok only for ok', function () {
    expect(EngagementStatus::Ok->isOk())->toBeTrue();
    expect(EngagementStatus::Failed->isOk())->toBeFalse();
    expect(EngagementStatus::Unsupported->isOk())->toBeFalse();
});

test('engagement status maps to the http status the action endpoints report', function () {
    expect(EngagementStatus::Ok->httpStatus())->toBe(200)
        ->and(EngagementStatus::AuthExpired->httpStatus())->toBe(403)
        ->and(EngagementStatus::Unsupported->httpStatus())->toBe(409)
        ->and(EngagementStatus::RateLimited->httpStatus())->toBe(429)
        ->and(EngagementStatus::Failed->httpStatus())->toBe(502);
});

test('no engagement status is ever reported as 422', function () {
    // 422 is load-bearing: Inertia's useHttp special-cases it into the
    // validation-errors path, parsing the body as `data.errors` and firing
    // onError instead of onHttpException. A connector failure returned as 422
    // would fire onError({}) — an empty bag — silently swallowing the message
    // and skipping the client's rollback. That is the exact silent-lie bug this
    // map fixes, so 422 stays reserved for real validation errors.
    expect(EngagementStatus::Failed->httpStatus())->not->toBe(422);

    foreach (EngagementStatus::cases() as $status) {
        expect($status->httpStatus())->not->toBe(422);
    }
});

test('reply status has the three lifecycle cases', function () {
    expect(array_map(fn (ReplyStatus $s) => $s->value, ReplyStatus::cases()))
        ->toBe(['pending', 'responded', 'archived']);
});

test('engagement config exposes enabled flag and window', function () {
    expect(config('engagement.enabled'))->toBeTrue();
    expect(config('engagement.window_days'))->toBe(7);
});

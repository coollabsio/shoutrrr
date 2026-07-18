<?php

use App\Enums\Platform;
use App\Support\UsageOperation;
use App\Support\UsagePricing;

it('does not bill X operations that X does not meter', function (string $operation): void {
    expect(app(UsagePricing::class)->costWeightMicrousd(Platform::X->value, $operation, 5))->toBe(0);
})->with([
    UsageOperation::MEDIA_UPLOAD,
    UsageOperation::MEDIA_STATUS_POLL,
    UsageOperation::DELETE,
    UsageOperation::REPLY_DELETE,
    UsageOperation::REPLY_LIKE,
    UsageOperation::REPLY_UNLIKE,
]);

it('bills X metrics reads as owned reads at $0.001', function (): void {
    $pricing = app(UsagePricing::class);

    expect($pricing->costWeightMicrousd(Platform::X->value, UsageOperation::METRICS_FETCH_POST, 3))->toBe(3_000)
        ->and($pricing->costWeightMicrousd(Platform::X->value, UsageOperation::METRICS_FETCH_ACCOUNT, 1))->toBe(1_000);
});

it('bills X reply reads at $0.005 per post and writes unchanged', function (): void {
    $pricing = app(UsagePricing::class);

    expect($pricing->costWeightMicrousd(Platform::X->value, UsageOperation::REPLIES_FETCH, 4))->toBe(20_000)
        ->and($pricing->costWeightMicrousd(Platform::X->value, UsageOperation::POST, 1))->toBe(15_000)
        ->and($pricing->costWeightMicrousd(Platform::X->value, UsageOperation::POST_WITH_URL, 1))->toBe(200_000);
});

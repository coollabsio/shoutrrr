<?php

use App\Support\UsageOperation;
use App\Support\UsagePricing;

it('converts configured unit costs into micro USD weights', function () {
    $pricing = app(UsagePricing::class);

    expect($pricing->costWeightMicrousd('x', UsageOperation::POST, 2))->toBe(30_000)
        ->and($pricing->costWeightMicrousd('x', UsageOperation::POST_WITH_URL, 1))->toBe(200_000)
        ->and($pricing->costWeightMicrousd('x', 'unknown', 1))->toBe(0);
});

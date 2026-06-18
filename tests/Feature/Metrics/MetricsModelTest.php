<?php

use App\Enums\MetricsStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;

test('account has many metric snapshots', function () {
    $account = ConnectedAccount::factory()->create();
    AccountMetric::factory()->for($account, 'account')->create(['followers' => 42]);

    expect($account->metrics)->toHaveCount(1);
    expect($account->metrics->first()->followers)->toBe(42);
});

test('post target casts metrics columns', function () {
    $target = PostTarget::factory()->create([
        'likes' => 5,
        'metrics_status' => MetricsStatus::Ok->value,
    ]);

    expect($target->refresh()->likes)->toBe(5);
    expect($target->metrics_status)->toBe(MetricsStatus::Ok);
});

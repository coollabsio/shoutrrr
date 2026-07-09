<?php

use App\Models\UsageEvent;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;

test('an api request records an API_REQUEST usage event when metering is on', function () {
    app(InstanceSettings::class)->update(['usage_tracking_enabled' => true]);
    [, $workspace, $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk();

    expect(UsageEvent::where('workspace_id', $workspace->id)
        ->where('operation', UsageOperation::API_REQUEST)->exists())->toBeTrue();
});

test('nothing is recorded when metering is off', function () {
    [, $workspace, $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertOk();

    expect(UsageEvent::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

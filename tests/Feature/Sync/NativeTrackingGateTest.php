<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;

function trackGate(): WorkspaceSubscriptionGate
{
    return app(WorkspaceSubscriptionGate::class);
}

function watch(Workspace $w): void
{
    $account = ConnectedAccount::factory()->create(['workspace_id' => $w->id]);
    ConnectedAccountNativeWatch::create(['connected_account_id' => $account->id, 'workspace_id' => $w->id, 'enabled_at' => now()]);
}

test('tracking is unlimited when subscriptions are off', function () {
    config(['subscriptions.enabled' => false, 'subscriptions.max_native_tracked' => 3]);
    $w = Workspace::factory()->create();
    for ($i = 0; $i < 4; $i++) {
        watch($w);
    }
    expect(trackGate()->canTrackNativeAccount($w))->toBeTrue();
});

test('a subscribed workspace is blocked at the tracking cap', function () {
    config(['subscriptions.enabled' => true, 'subscriptions.max_native_tracked' => 3]);
    $w = Workspace::factory()->create(['is_initial' => false]);
    $w->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.fake()->uuid(), 'stripe_status' => 'active', 'stripe_price' => 'price_test', 'quantity' => 1]);

    expect(trackGate()->canTrackNativeAccount($w))->toBeTrue();
    for ($i = 0; $i < 3; $i++) {
        watch($w);
    }
    expect(trackGate()->canTrackNativeAccount($w->fresh()))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Models\SyncPipeline;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;

function syncGate(): WorkspaceSubscriptionGate
{
    return app(WorkspaceSubscriptionGate::class);
}

test('subscriptions disabled means unlimited pipelines', function () {
    config(['subscriptions.enabled' => false, 'subscriptions.max_sync_pipelines' => 3]);
    $workspace = Workspace::factory()->create();
    SyncPipeline::factory()->count(5)->create(['workspace_id' => $workspace->id]);

    expect(syncGate()->canCreateSyncPipeline($workspace))->toBeTrue();
});

test('initial workspace is unlimited even with subscriptions enabled', function () {
    config(['subscriptions.enabled' => true, 'subscriptions.max_sync_pipelines' => 3]);
    $workspace = Workspace::factory()->create(['is_initial' => true]);
    SyncPipeline::factory()->count(5)->create(['workspace_id' => $workspace->id]);

    expect(syncGate()->canCreateSyncPipeline($workspace))->toBeTrue();
});

test('a subscribed workspace is blocked at the cap', function () {
    config(['subscriptions.enabled' => true, 'subscriptions.max_sync_pipelines' => 3]);
    $workspace = Workspace::factory()->create(['is_initial' => false]);
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    expect(syncGate()->canCreateSyncPipeline($workspace))->toBeTrue();
    SyncPipeline::factory()->count(3)->create(['workspace_id' => $workspace->id]);
    expect(syncGate()->canCreateSyncPipeline($workspace->fresh()))->toBeFalse();
});

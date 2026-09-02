<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('an owner can create a pipeline', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->post('/settings/workspace/sync-pipelines', [
        'name' => 'X to LinkedIn',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$dest->id],
    ])->assertRedirect();

    $pipeline = SyncPipeline::first();
    expect($pipeline->name)->toBe('X to LinkedIn')
        ->and($pipeline->destinations->pluck('id')->all())->toBe([$dest->id]);
});

test('creation is blocked at the cap of 3 when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true, 'subscriptions.max_sync_pipelines' => 3]);
    [, $workspace] = ownerActingIn();
    $workspace->forceFill(['is_initial' => false])->save();
    $workspace->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active', 'stripe_price' => 'price_test', 'quantity' => 1,
    ]);
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    SyncPipeline::factory()->count(3)->create(['workspace_id' => $workspace->id]);

    $this->post('/settings/workspace/sync-pipelines', [
        'name' => 'Over cap',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$dest->id],
    ])->assertSessionHasErrors('name');

    expect(SyncPipeline::count())->toBe(3);
});

test('a member without settings.manage cannot create a pipeline', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post('/settings/workspace/sync-pipelines', [
        'name' => 'x', 'source_connected_account_id' => $source->id, 'destination_connected_account_ids' => [],
    ])->assertForbidden();
});

test('an owner can toggle and delete a pipeline', function () {
    [, $workspace] = ownerActingIn();
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'enabled' => true]);

    $this->patch("/settings/workspace/sync-pipelines/{$pipeline->id}", ['enabled' => false])->assertRedirect();
    expect($pipeline->fresh()->enabled)->toBeFalse();

    $this->delete("/settings/workspace/sync-pipelines/{$pipeline->id}")->assertRedirect();
    $this->assertDatabaseMissing('sync_pipelines', ['id' => $pipeline->id]);
});

test('pipelines from another workspace are not manageable', function () {
    ownerActingIn();
    $foreign = SyncPipeline::factory()->create();

    $this->delete("/settings/workspace/sync-pipelines/{$foreign->id}")->assertNotFound();
});

test('the settings page renders', function () {
    [, $workspace] = ownerActingIn();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->get('/settings/workspace/sync-pipelines')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/workspace/sync-pipelines')->has('accounts'));
});

test('the settings page exposes native tracking data', function () {
    [, $workspace] = ownerActingIn();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);

    $this->get('/settings/workspace/sync-pipelines')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('trackableAccounts')
            ->has('trackedAccountIds')
            ->has('canTrack'));
});

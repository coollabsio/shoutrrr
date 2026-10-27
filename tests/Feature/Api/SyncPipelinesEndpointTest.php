<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\SyncPipeline;
use App\Models\Workspace;

function billedWorkspace(Workspace $workspace): void
{
    config(['subscriptions.enabled' => true]);
    $workspace->forceFill(['is_initial' => false])->save();
}

test('sync pipelines require a bearer token', function () {
    $this->getJson('/api/v1/sync-pipelines')->assertUnauthorized();
});

test('creates a pipeline and tracks the source when asked', function () {
    [, $workspace, $token] = issuedKey();
    $source = ConnectedAccount::factory()->for($workspace)->bluesky()->create();
    $destination = ConnectedAccount::factory()->for($workspace)->create();

    $response = $this->withToken($token)->postJson('/api/v1/sync-pipelines', [
        'name' => 'Bluesky to X',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$destination->id],
        'track_source' => true,
    ])->assertCreated();

    $pipeline = SyncPipeline::query()->sole();

    $response->assertExactJson([
        'id' => $pipeline->id,
        'name' => 'Bluesky to X',
        'enabled' => true,
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$destination->id],
        'tracked_source' => true,
    ]);

    expect(ConnectedAccountNativeWatch::query()->where('connected_account_id', $source->id)->exists())->toBeTrue();
});

test('lists only this workspace pipelines', function () {
    [, $workspace, $token] = issuedKey();
    $source = ConnectedAccount::factory()->for($workspace)->create();
    $destination = ConnectedAccount::factory()->for($workspace)->create();
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'source_connected_account_id' => $source->id]);
    $pipeline->destinations()->sync([$destination->id]);
    SyncPipeline::factory()->create();

    $this->withToken($token)->getJson('/api/v1/sync-pipelines')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pipeline->id)
        ->assertJsonPath('data.0.destination_connected_account_ids', [$destination->id]);
});

test('rejects a source that is also a destination', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->postJson('/api/v1/sync-pipelines', [
        'name' => 'Loop',
        'source_connected_account_id' => $account->id,
        'destination_connected_account_ids' => [$account->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('destination_connected_account_ids');

    expect(SyncPipeline::query()->count())->toBe(0);
});

test('rejects accounts from another workspace', function () {
    [, $workspace, $token] = issuedKey();
    $source = ConnectedAccount::factory()->for($workspace)->create();
    $foreign = ConnectedAccount::factory()->create();

    $this->withToken($token)->postJson('/api/v1/sync-pipelines', [
        'name' => 'Leak',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$foreign->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('destination_connected_account_ids.0');
});

test('enforces the plan pipeline limit', function () {
    [, $workspace, $token] = issuedKey();
    billedWorkspace($workspace);
    $source = ConnectedAccount::factory()->for($workspace)->create();
    $destination = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->postJson('/api/v1/sync-pipelines', [
        'name' => 'Over the cap',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$destination->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(SyncPipeline::query()->count())->toBe(0);
});

test('updates a pipeline and checks the final source against stored destinations', function () {
    [, $workspace, $token] = issuedKey();
    $source = ConnectedAccount::factory()->for($workspace)->create();
    $destination = ConnectedAccount::factory()->for($workspace)->create();
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'source_connected_account_id' => $source->id]);
    $pipeline->destinations()->sync([$destination->id]);

    $this->withToken($token)->patchJson("/api/v1/sync-pipelines/{$pipeline->id}", [
        'source_connected_account_id' => $destination->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('destination_connected_account_ids');

    $this->withToken($token)->patchJson("/api/v1/sync-pipelines/{$pipeline->id}", [
        'name' => 'Renamed',
        'enabled' => false,
    ])->assertOk()
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('source_connected_account_id', $source->id)
        ->assertJsonPath('destination_connected_account_ids', [$destination->id]);
});

test('deletes a pipeline', function () {
    [, $workspace, $token] = issuedKey();
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id]);

    $this->withToken($token)->deleteJson("/api/v1/sync-pipelines/{$pipeline->id}")
        ->assertOk()
        ->assertExactJson(['deleted' => true, 'id' => $pipeline->id]);

    expect(SyncPipeline::withoutGlobalScopes()->find($pipeline->id))->toBeNull();
});

test('another workspace pipeline is 404', function (string $method) {
    [, , $token] = issuedKey();
    $foreign = SyncPipeline::factory()->create();

    $this->withToken($token)->json($method, "/api/v1/sync-pipelines/{$foreign->id}", ['name' => 'x'])->assertNotFound();

    expect(SyncPipeline::withoutGlobalScopes()->find($foreign->id)?->name)->toBe($foreign->name);
})->with(['PATCH', 'DELETE']);

test('a member cannot manage sync pipelines', function (string $method, string $path) {
    [$user, $workspace, $token] = issuedKey();
    $workspace->members()->where('user_id', $user->id)->update(['role' => 'member']);
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id]);

    $this->withToken($token)->json($method, str_replace('{id}', $pipeline->id, $path), ['name' => 'x'])->assertForbidden();
})->with([
    'list' => ['GET', '/api/v1/sync-pipelines'],
    'create' => ['POST', '/api/v1/sync-pipelines'],
    'update' => ['PATCH', '/api/v1/sync-pipelines/{id}'],
    'delete' => ['DELETE', '/api/v1/sync-pipelines/{id}'],
]);

test('a read-only key can list but not write pipelines', function () {
    [, $workspace, $token] = issuedKey('read');
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id]);

    $this->withToken($token)->getJson('/api/v1/sync-pipelines')->assertOk();
    $this->withToken($token)->postJson('/api/v1/sync-pipelines', ['name' => 'x'])->assertForbidden();
    $this->withToken($token)->deleteJson("/api/v1/sync-pipelines/{$pipeline->id}")->assertForbidden();
});

test('tracks and untracks an account natively', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->bluesky()->create();

    $this->withToken($token)->postJson("/api/v1/connected-accounts/{$account->id}/native-tracking")
        ->assertOk()
        ->assertExactJson(['id' => $account->id, 'native_tracking' => true]);

    expect(ConnectedAccountNativeWatch::query()->where('connected_account_id', $account->id)->exists())->toBeTrue();

    $this->withToken($token)->deleteJson("/api/v1/connected-accounts/{$account->id}/native-tracking")
        ->assertOk()
        ->assertExactJson(['id' => $account->id, 'native_tracking' => false]);

    expect(ConnectedAccountNativeWatch::query()->where('connected_account_id', $account->id)->exists())->toBeFalse();
});

test('native tracking rejects an unsupported platform', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->discord()->create();

    $this->withToken($token)->postJson("/api/v1/connected-accounts/{$account->id}/native-tracking")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account' => 'Discord does not support native tracking']);
});

test('native tracking enforces the plan limit', function () {
    [, $workspace, $token] = issuedKey();
    billedWorkspace($workspace);
    $account = ConnectedAccount::factory()->for($workspace)->bluesky()->create();

    $this->withToken($token)->postJson("/api/v1/connected-accounts/{$account->id}/native-tracking")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect(ConnectedAccountNativeWatch::query()->count())->toBe(0);
});

test('native tracking is 404 for another workspace and 403 for a member', function () {
    [$user, $workspace, $token] = issuedKey();
    $foreign = ConnectedAccount::factory()->bluesky()->create();

    $this->withToken($token)->postJson("/api/v1/connected-accounts/{$foreign->id}/native-tracking")->assertNotFound();

    $workspace->members()->where('user_id', $user->id)->update(['role' => 'member']);
    $account = ConnectedAccount::factory()->for($workspace)->bluesky()->create();

    $this->withToken($token)->postJson("/api/v1/connected-accounts/{$account->id}/native-tracking")->assertForbidden();
});

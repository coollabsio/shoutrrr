<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreateSyncPipelineTool;
use App\Mcp\Tools\DeleteSyncPipelineTool;
use App\Mcp\Tools\ListSyncPipelinesTool;
use App\Mcp\Tools\SetNativeTrackingTool;
use App\Mcp\Tools\UpdatePostingScheduleTool;
use App\Mcp\Tools\UpdateSyncPipelineTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\PostingSchedule;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;

test('sync pipeline tools create, update, and delete within plan rules', function (): void {
    [$user, $workspace] = mcpOwner();
    $source = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X]);
    $destination = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Bluesky]);

    ShoutrrrServer::actingAs($user)->tool(ListSyncPipelinesTool::class, [])
        ->assertOk()
        ->assertSee('"pipelines": []');

    $created = ShoutrrrServer::actingAs($user)->tool(CreateSyncPipelineTool::class, [
        'name' => 'X to Bluesky',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$destination->id],
        'track_source' => true,
    ]);
    $created->assertOk()->assertSee('X to Bluesky')->assertSee('"tracked_source": true');

    $pipeline = SyncPipeline::query()->where('workspace_id', $workspace->id)->first();
    expect($pipeline)->not->toBeNull()
        ->and($pipeline->destinations()->pluck('connected_accounts.id')->all())->toBe([$destination->id])
        ->and(ConnectedAccountNativeWatch::query()->where('connected_account_id', $source->id)->exists())->toBeTrue();

    ShoutrrrServer::actingAs($user)->tool(CreateSyncPipelineTool::class, [
        'name' => 'Loop',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$source->id],
    ])->assertHasErrors();

    ShoutrrrServer::actingAs($user)->tool(UpdateSyncPipelineTool::class, [
        'pipeline_id' => $pipeline->id,
        'name' => 'Renamed',
        'enabled' => false,
    ])->assertOk()->assertSee('Renamed');
    expect($pipeline->fresh()->enabled)->toBeFalse();

    ShoutrrrServer::actingAs($user)->tool(DeleteSyncPipelineTool::class, ['pipeline_id' => $pipeline->id])
        ->assertHasErrors();
    expect(SyncPipeline::find($pipeline->id))->not->toBeNull();

    ShoutrrrServer::actingAs($user)->tool(DeleteSyncPipelineTool::class, [
        'pipeline_id' => $pipeline->id,
        'confirm' => true,
    ])->assertOk();
    expect(SyncPipeline::find($pipeline->id))->toBeNull();
});

test('a member cannot create a sync pipeline', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $source = ConnectedAccount::factory()->for($workspace)->create();
    $destination = ConnectedAccount::factory()->for($workspace)->create();

    ShoutrrrServer::actingAs($user)->tool(CreateSyncPipelineTool::class, [
        'name' => 'Nope',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$destination->id],
    ])->assertHasErrors();
});

test('update_posting_schedule stores the timezone and weekly slots', function (): void {
    [$user, $workspace] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(UpdatePostingScheduleTool::class, [
        'timezone' => 'America/New_York',
        'slots' => [
            ['weekday' => 1, 'hour' => 9, 'minute' => 30],
            ['weekday' => 1, 'hour' => 9, 'minute' => 30],
        ],
    ])->assertOk()->assertSee('America/New_York');

    $schedule = PostingSchedule::query()->where('workspace_id', $workspace->id)->first();
    expect($schedule)->not->toBeNull()
        ->and($schedule->timezone)->toBe('America/New_York')
        ->and($schedule->slots)->toHaveCount(1)
        ->and($schedule->slots->first()->minute)->toBe(30);
});

test('set_native_tracking follows platform support', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Bluesky]);
    $discord = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Discord]);

    ShoutrrrServer::actingAs($user)->tool(SetNativeTrackingTool::class, [
        'account_id' => $discord->id,
        'enabled' => true,
    ])->assertHasErrors();

    ShoutrrrServer::actingAs($user)->tool(SetNativeTrackingTool::class, [
        'account_id' => $account->id,
        'enabled' => true,
    ])->assertOk();
    expect(ConnectedAccountNativeWatch::query()->where('connected_account_id', $account->id)->exists())->toBeTrue();

    ShoutrrrServer::actingAs($user)->tool(SetNativeTrackingTool::class, [
        'account_id' => $account->id,
        'enabled' => false,
    ])->assertOk();
    expect(ConnectedAccountNativeWatch::query()->where('connected_account_id', $account->id)->exists())->toBeFalse();
});

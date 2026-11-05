<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\DisconnectAccountTool;
use App\Mcp\Tools\GetAccountConnectUrlTool;
use App\Mcp\Tools\ListPlatformsTool;
use App\Mcp\Tools\MakeDefaultAccountTool;
use App\Mcp\Tools\ReconnectAccountTool;
use App\Mcp\Tools\SetAutoRepostTool;
use App\Mcp\Tools\ToggleAccountTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('list_platforms includes classic connect targets', function (): void {
    [$user] = mcpOwner();

    $response = ShoutrrrServer::actingAs($user)->tool(ListPlatformsTool::class, []);

    $response->assertOk()
        ->assertSee('bluesky')
        ->assertSee('discord')
        ->assertSee('linkedin-pages')
        ->assertSee('facebook');
});

test('get_account_connect_url returns the classic oauth start url', function (): void {
    config([
        'services.x.client_id' => 'test-client',
        'services.x.client_secret' => 'test-secret',
        'services.facebook.client_id' => 'fb-client',
        'services.facebook.client_secret' => 'fb-secret',
    ]);
    [$user] = mcpOwner();

    $x = ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'x']);
    $x->assertOk()->assertSee('accounts/connect/x');

    $meta = ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'instagram']);
    $meta->assertOk()->assertSee('accounts/connect/meta');

    $discord = ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'discord']);
    $discord->assertOk()->assertSee('webhook')->assertSee('/accounts');
});

test('get_account_connect_url refuses an unknown or unconfigured platform', function (): void {
    config(['services.x.client_id' => null, 'services.x.client_secret' => null]);
    [$user] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'nope'])
        ->assertHasErrors();

    ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'x'])
        ->assertHasErrors();
});

test('a member cannot start an account connection', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    expect(WorkspaceMembership::query()->where('user_id', $user->id)->value('role'))->toBe(WorkspaceRole::Member);

    ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'bluesky'])
        ->assertHasErrors();
});

test('disconnect_account requires confirmation and then removes the account', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $workspace->forceFill(['default_connected_account_id' => $account->id])->save();

    ShoutrrrServer::actingAs($user)->tool(DisconnectAccountTool::class, ['account_id' => $account->id])
        ->assertHasErrors();
    expect(ConnectedAccount::find($account->id))->not->toBeNull();

    ShoutrrrServer::actingAs($user)->tool(DisconnectAccountTool::class, [
        'account_id' => $account->id,
        'confirm' => true,
    ])->assertOk();

    expect(ConnectedAccount::find($account->id))->toBeNull()
        ->and($workspace->fresh()->default_connected_account_id)->toBeNull();
});

test('disconnect_account names the pipelines and scheduled posts it removes', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create(['handle' => '@alice']);
    $other = ConnectedAccount::factory()->for($workspace)->create();

    SyncPipeline::factory()->count(2)->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $account->id,
    ]);
    SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $other->id,
    ])->destinations()->attach($account->id);

    $scheduled = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
    $draft = Post::factory()->create(['workspace_id' => $workspace->id]);
    PostTarget::factory()->create(['post_id' => $scheduled->id, 'connected_account_id' => $account->id]);
    PostTarget::factory()->create(['post_id' => $draft->id, 'connected_account_id' => $account->id]);

    ShoutrrrServer::actingAs($user)->tool(DisconnectAccountTool::class, ['account_id' => $account->id])
        ->assertHasErrors([
            'This will disconnect @alice and delete its stored credentials. It also deletes 2 sync pipeline(s) that post from it, removes it as a destination from 1 sync pipeline(s), and removes it from 1 scheduled post(s) and 1 other post(s).',
        ]);
});

test('disconnect_account does not see another workspace', function (): void {
    [$user] = mcpOwner();
    $foreign = ConnectedAccount::factory()->create();

    ShoutrrrServer::actingAs($user)->tool(DisconnectAccountTool::class, [
        'account_id' => $foreign->id,
        'confirm' => true,
    ])->assertHasErrors();

    expect(ConnectedAccount::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});

test('toggle_account disables an account and clears the default', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create();
    $workspace->forceFill(['default_connected_account_id' => $account->id])->save();

    ShoutrrrServer::actingAs($user)->tool(ToggleAccountTool::class, [
        'account_id' => $account->id,
        'enabled' => false,
    ])->assertOk();

    expect($account->fresh()->isDisabled())->toBeTrue()
        ->and($workspace->fresh()->default_connected_account_id)->toBeNull();
});

test('reconnect_account returns the classic oauth url', function (): void {
    config([
        'services.x.client_id' => 'test-client',
        'services.x.client_secret' => 'test-secret',
    ]);
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X,
        'auth_method' => 'oauth',
    ]);

    ShoutrrrServer::actingAs($user)->tool(ReconnectAccountTool::class, ['account_id' => $account->id])
        ->assertOk()
        ->assertSee('accounts/connect/x');
});

test('make_default_account and set_auto_repost update the account', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X]);
    $discord = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::Discord]);

    ShoutrrrServer::actingAs($user)->tool(MakeDefaultAccountTool::class, ['account_id' => $account->id])
        ->assertOk();
    expect($workspace->fresh()->default_connected_account_id)->toBe($account->id);

    ShoutrrrServer::actingAs($user)->tool(SetAutoRepostTool::class, [
        'account_id' => $account->id,
        'enabled' => true,
    ])->assertOk();
    expect($account->fresh()->autoRepostEnabled())->toBeTrue();

    ShoutrrrServer::actingAs($user)->tool(SetAutoRepostTool::class, [
        'account_id' => $discord->id,
        'enabled' => true,
    ])->assertOk();
    expect($discord->fresh()->autoRepostEnabled())->toBeFalse();
});

test('set_auto_repost takes min_percentile as a fraction between 0 and 1', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X]);

    ShoutrrrServer::actingAs($user)->tool(SetAutoRepostTool::class, [
        'account_id' => $account->id,
        'enabled' => true,
        'min_percentile' => 0.75,
    ])->assertOk();
    expect($account->fresh()->autoRepostConfig()['min_percentile'])->toBe(0.75);

    ShoutrrrServer::actingAs($user)->tool(SetAutoRepostTool::class, [
        'account_id' => $account->id,
        'enabled' => true,
        'min_percentile' => 75,
    ])->assertHasErrors();
    expect($account->fresh()->autoRepostConfig()['min_percentile'])->toBe(0.75);
});

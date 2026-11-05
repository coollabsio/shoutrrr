<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\GetAccountConnectUrlTool;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

beforeEach(function (): void {
    config([
        'services.x.client_id' => 'cid',
        'services.x.client_secret' => 'secret',
        'services.x.redirect' => 'https://app.test/accounts/callback/x',
    ]);
});

/**
 * Make the user an owner of a second workspace and leave the browser session in it.
 */
function browserInOtherWorkspace(User $user): Workspace
{
    $other = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $other->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $other->id])->save();

    return $other;
}

test('an agent connect url switches the browser to the agent workspace before oauth', function (): void {
    [$user, $agentWorkspace, $token] = issuedKey();
    browserInOtherWorkspace($user);
    fakeOAuthUser('x', ['id' => 'x-1']);

    $url = $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')->assertOk()->json('url');

    expect($url)->toContain('workspace='.$agentWorkspace->id)->toContain('signature=');

    $this->actingAs($user->fresh())->get($url)->assertRedirect(route('accounts.connect', ['platform' => 'x']));

    expect($user->fresh()->current_workspace_id)->toBe($agentWorkspace->id);

    $this->get(route('accounts.connect', ['platform' => 'x']))->assertRedirect('https://provider.test/oauth');
});

test('an mcp connect url carries the bound workspace', function (): void {
    [$user, $workspace] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(GetAccountConnectUrlTool::class, ['platform' => 'x'])
        ->assertOk()
        ->assertSee('workspace='.$workspace->id)
        ->assertSee('signature=');
});

test('a reconnect url for a discord account switches workspace on the accounts page', function (): void {
    [$user, $agentWorkspace, $token] = issuedKey();
    browserInOtherWorkspace($user);
    $account = ConnectedAccount::factory()->for($agentWorkspace)->discord()->create();

    $url = $this->withToken($token)->getJson("/api/v1/connected-accounts/{$account->id}/reconnect-url")->assertOk()->json('url');

    $this->actingAs($user->fresh())->get($url)->assertRedirect(route('accounts.index'));

    expect($user->fresh()->current_workspace_id)->toBe($agentWorkspace->id);
});

test('a signed connect link for a workspace the user is not in is forbidden', function (): void {
    [, , $token] = issuedKey();
    $stranger = User::factory()->create();
    $strangerWorkspace = browserInOtherWorkspace($stranger);

    $url = $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')->assertOk()->json('url');

    $this->actingAs($stranger->fresh())->get($url)->assertForbidden();

    expect($stranger->fresh()->current_workspace_id)->toBe($strangerWorkspace->id);
});

test('a tampered or unsigned workspace link is forbidden', function (): void {
    [$user, $agentWorkspace, $token] = issuedKey();
    $browserWorkspace = browserInOtherWorkspace($user);

    $url = $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')->assertOk()->json('url');
    $tampered = str_replace('workspace='.$agentWorkspace->id, 'workspace='.$browserWorkspace->id, $url);

    $this->actingAs($user->fresh())->get($tampered)->assertForbidden();
    $this->get(route('accounts.connect', ['platform' => 'x', 'workspace' => $agentWorkspace->id]))->assertForbidden();

    expect($user->fresh()->current_workspace_id)->toBe($browserWorkspace->id);
});

test('an expired workspace link is forbidden', function (): void {
    [$user, , $token] = issuedKey();
    browserInOtherWorkspace($user);

    $url = $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')->assertOk()->json('url');

    $this->travel(61)->minutes();

    $this->actingAs($user->fresh())->get($url)->assertForbidden();
});

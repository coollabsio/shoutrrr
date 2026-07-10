<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;

function discordOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

test('an owner connects a Discord webhook and the URL is sealed in the secret', function () {
    discordOwner();
    $url = 'https://discord.com/api/webhooks/999/tok-123';
    Http::fake([$url => Http::response([
        'id' => '999', 'name' => 'Releases', 'channel_id' => '5', 'guild_id' => '7',
    ])]);

    test()->post('/accounts/connect/discord', ['webhook_url' => $url])
        ->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', '999');
    expect($account->platform)->toBe(Platform::Discord)
        ->and($account->auth_method)->toBe('webhook')
        ->and($account->handle)->toBe('Releases')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->secret->access_token)->toBe($url)
        ->and($account->secret->session)->toMatchArray(['channel_id' => '5']);
});

test('an invalid webhook URL redirects back with an error and connects nothing', function () {
    discordOwner();
    Http::fake();

    test()->post('/accounts/connect/discord', ['webhook_url' => 'https://evil.com/api/webhooks/1/t'])
        ->assertRedirect()->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('a member cannot connect a Discord webhook', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($user)->post('/accounts/connect/discord', [
        'webhook_url' => 'https://discord.com/api/webhooks/1/t',
    ])->assertForbidden();
});

test('the webhook_url is required', function () {
    discordOwner();

    test()->post('/accounts/connect/discord', [])
        ->assertSessionHasErrors('webhook_url');
});

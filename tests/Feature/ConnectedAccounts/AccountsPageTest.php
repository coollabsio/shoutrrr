<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Inertia\Testing\AssertableInertia as Assert;

function viewerInWorkspace(WorkspaceRole $role): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'handle' => '@listed',
        'capabilities' => [
            'x_premium' => true,
            'max_text_length' => 25_000,
            'verified_type' => 'blue',
        ],
    ]);

    return $user;
}

test('the accounts page lists accounts and exposes capabilities and canManage to owners', function () {
    $owner = viewerInWorkspace(WorkspaceRole::Owner);

    test()->actingAs($owner)->get('/accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/index')
            ->where('canManage', true)
            ->has('capabilities', 3)
            ->has('accounts', 1)
            ->where('accounts.0.handle', '@listed')
            ->where('accounts.0.x_premium', true)
            ->where('accounts.0.max_text_length', 25_000)
            ->where('accounts.0.is_default', false)
            ->missing('accounts.0.secret'),
        );
});

test('the accounts page exposes a saved custom PDS so reconnect can replay it', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

    $default = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky,
        'handle' => '@default-pds',
        'auth_method' => 'oauth',
        'created_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $default->id,
        'session' => ['pds' => 'https://bsky.social'],
    ]);

    $custom = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky,
        'handle' => '@custom',
        'auth_method' => 'oauth',
        'created_at' => now(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $custom->id,
        'session' => ['pds' => 'https://pds.example'],
    ]);

    test()->actingAs($owner)->get('/accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/index')
            ->has('accounts', 2)
            ->where('accounts.0.handle', '@custom')
            ->where('accounts.0.pds_url', 'https://pds.example')
            ->where('accounts.1.handle', '@default-pds')
            ->where('accounts.1.pds_url', null),
        );
});

test('owners can set a workspace default account from the accounts page', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    test()->actingAs($owner)
        ->post(route('accounts.default', $account))
        ->assertRedirect(route('accounts.index'));

    expect($workspace->fresh()->default_connected_account_id)->toBe($account->id);
});

test('members cannot set a workspace default account', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
    ]);
    $member->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    test()->actingAs($member)
        ->post(route('accounts.default', $account))
        ->assertForbidden();

    expect($workspace->fresh()->default_connected_account_id)->toBeNull();
});

test('the accounts page marks and lists the workspace default account first', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@regular']);
    $default = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@default']);
    $workspace->forceFill(['default_connected_account_id' => $default->id])->save();

    test()->actingAs($owner)->get('/accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.id', $default->id)
            ->where('accounts.0.is_default', true)
            ->where('accounts.0.handle', '@default'),
        );
});

test('disconnecting the workspace default account clears the default', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $workspace->forceFill(['default_connected_account_id' => $account->id])->save();

    test()->actingAs($owner)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('accounts.index'));

    expect($workspace->fresh()->default_connected_account_id)->toBeNull();
});

test('disconnecting an account clears stale inertia history on the next accounts page response', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    test()->actingAs($owner)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('accounts.index'));

    test()->actingAs($owner)
        ->get(route('accounts.index'))
        ->assertInertia(fn (Assert $page) => expect($page->toArray())
            ->toHaveKey('clearHistory', true));
});

test('members see the list but cannot manage', function () {
    $member = viewerInWorkspace(WorkspaceRole::Member);

    test()->actingAs($member)->get('/accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/index')
            ->where('canManage', false),
        );
});

test('the accounts page requires authentication', function () {
    test()->get('/accounts')->assertRedirect(route('login'));
});

test('get requests to account member paths return not found instead of method not allowed', function () {
    $owner = viewerInWorkspace(WorkspaceRole::Owner);

    test()->actingAs($owner)
        ->get('/accounts/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404));
});

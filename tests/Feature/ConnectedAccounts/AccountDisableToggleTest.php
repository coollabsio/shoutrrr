<?php

use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Inertia\Testing\AssertableInertia as Assert;

function toggleOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace];
}

test('toggling disables then re-enables an account', function () {
    [$user, $workspace] = toggleOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
    ]);

    test()->actingAs($user)->patch("/accounts/{$account->id}/toggle")
        ->assertRedirect(route('accounts.index'));
    expect($account->fresh()->isDisabled())->toBeTrue();

    test()->actingAs($user)->patch("/accounts/{$account->id}/toggle")
        ->assertRedirect(route('accounts.index'));
    expect($account->fresh()->isDisabled())->toBeFalse();
});

test('disabling the workspace default clears the default', function () {
    [$user, $workspace] = toggleOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
    ]);
    $workspace->forceFill(['default_connected_account_id' => $account->id])->save();

    test()->actingAs($user)->patch("/accounts/{$account->id}/toggle");

    expect($account->fresh()->isDisabled())->toBeTrue()
        ->and($workspace->fresh()->default_connected_account_id)->toBeNull();
});

test('a member without account-manage permission cannot toggle', function () {
    [$owner, $workspace] = toggleOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $owner->id,
    ]);

    $member = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => WorkspaceRole::Member,
    ]);
    $member->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($member)->patch("/accounts/{$account->id}/toggle")
        ->assertForbidden();
    expect($account->fresh()->isDisabled())->toBeFalse();
});

test('the accounts page exposes the disabled flag', function () {
    [$user, $workspace] = toggleOwner();
    ConnectedAccount::factory()->disabled()->create([
        'workspace_id' => $workspace->id,
        'handle' => '@off',
        'connected_by_user_id' => $user->id,
    ]);

    test()->actingAs($user)->get('/accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/index')
            ->where('accounts.0.handle', '@off')
            ->where('accounts.0.disabled', true),
        );
});

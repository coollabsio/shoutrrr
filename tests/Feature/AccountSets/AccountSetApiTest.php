<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function memberForSets(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

test('a member can create an account set with members', function () {
    [$user, $workspace] = memberForSets();
    $a = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);

    test()->postJson('/account-sets', [
        'name' => 'Launch crew',
        'connected_account_ids' => [$a->id],
    ])->assertCreated()->assertJsonPath('account_set.name', 'Launch crew');

    expect(AccountSet::withoutGlobalScopes()->count())->toBe(1);
});

test('a member can delete an account set', function () {
    [$user, $workspace] = memberForSets();
    $set = AccountSet::factory()->create(['workspace_id' => $workspace->id]);

    test()->delete("/account-sets/{$set->id}")->assertRedirect();

    expect(AccountSet::withoutGlobalScopes()->whereKey($set->id)->exists())->toBeFalse();
});

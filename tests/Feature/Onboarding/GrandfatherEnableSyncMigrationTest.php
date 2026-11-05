<?php

use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;

function runGrandfatherEnableSyncMigration(): void
{
    (include database_path('migrations/2026_11_05_105944_grandfather_enable_sync_onboarding_step.php'))->up();
}

/**
 * A workspace that satisfied every step of the pre-sync checklist.
 */
function workspaceCompleteUnderOldRules(array $progress = ['timezone']): Workspace
{
    $workspace = Workspace::factory()->create(['onboarding_progress' => $progress]);
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id]);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $owner->id]);

    return $workspace;
}

test('grandfathers enable_sync only into workspaces complete under the old checklist', function () {
    $complete = workspaceCompleteUnderOldRules();

    $completeViaInvitation = Workspace::factory()->create(['onboarding_progress' => ['timezone']]);
    $owner = User::factory()->create(['current_workspace_id' => $completeViaInvitation->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $completeViaInvitation->id, 'user_id' => $owner->id]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $completeViaInvitation->id]);
    ConnectedAccount::factory()->create(['workspace_id' => $completeViaInvitation->id]);
    Post::factory()->create(['workspace_id' => $completeViaInvitation->id, 'author_id' => $owner->id]);

    $missingTimezone = workspaceCompleteUnderOldRules(progress: []);

    $soloNoInvite = Workspace::factory()->create(['onboarding_progress' => ['timezone']]);
    $solo = User::factory()->create(['current_workspace_id' => $soloNoInvite->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $soloNoInvite->id, 'user_id' => $solo->id]);
    ConnectedAccount::factory()->create(['workspace_id' => $soloNoInvite->id]);
    Post::factory()->create(['workspace_id' => $soloNoInvite->id, 'author_id' => $solo->id]);

    $fresh = Workspace::factory()->create();

    runGrandfatherEnableSyncMigration();

    expect($complete->fresh()->onboarding_progress)->toBe(['timezone', 'enable_sync'])
        ->and($completeViaInvitation->fresh()->onboarding_progress)->toBe(['timezone', 'enable_sync'])
        ->and($missingTimezone->fresh()->onboarding_progress)->toBe([])
        ->and($soloNoInvite->fresh()->onboarding_progress)->toBe(['timezone'])
        ->and($fresh->fresh()->onboarding_progress)->toBeNull();
});

test('is idempotent', function () {
    $complete = workspaceCompleteUnderOldRules();

    runGrandfatherEnableSyncMigration();
    runGrandfatherEnableSyncMigration();

    expect($complete->fresh()->onboarding_progress)->toBe(['timezone', 'enable_sync']);
});

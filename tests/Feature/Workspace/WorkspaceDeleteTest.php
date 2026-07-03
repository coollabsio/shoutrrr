<?php

use App\Exceptions\CannotDeleteInitialWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('owner cannot delete their last workspace', function () {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertSessionHasErrors('workspace');

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    $this->assertSame($workspace->id, $owner->fresh()->current_workspace_id);
});

test('workspace settings disables deletion for the last workspace', function () {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('settings.workspace'))
        ->assertInertia(fn ($page) => $page
            ->where('canDelete', false)
        );
});

test('workspace settings disables deletion for the initial workspace on a cloud instance', function () {
    config(['subscriptions.enabled' => true]);
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('settings.workspace'))
        ->assertInertia(fn ($page) => $page
            ->where('canDelete', false)
            ->where('deleteDisabledReason', 'The initial workspace of this instance cannot be deleted.')
        );
});

test('owner can delete workspace and memberships cascade when another workspace remains', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertRedirect();

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    $this->assertDatabaseMissing('workspace_memberships', ['workspace_id' => $workspace->id]);
    $this->assertSame($other->id, $owner->fresh()->current_workspace_id);
});

test('non owner cannot delete workspace', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)->delete(route('workspaces.destroy', $workspace))->assertForbidden();

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
});

test('the first workspace created on the instance is flagged as initial', function () {
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();

    expect($first->refresh()->is_initial)->toBeTrue()
        ->and($second->refresh()->is_initial)->toBeFalse();
});

test('the initial workspace cannot be deleted on a cloud instance', function () {
    config(['subscriptions.enabled' => true]);
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertSessionHasErrors('workspace');

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);

    // Model-level guard is loud when the controller is bypassed.
    expect(fn () => $workspace->refresh()->delete())->toThrow(CannotDeleteInitialWorkspace::class);
    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
});

test('deleting current workspace reassigns to another membership', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $a->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $a->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $b->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $a))->assertRedirect();

    $this->assertSame($b->id, $owner->fresh()->current_workspace_id);
});

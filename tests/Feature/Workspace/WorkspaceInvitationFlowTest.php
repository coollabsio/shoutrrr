<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;

test('logged in invitee accepts via link', function () {
    $workspace = Workspace::factory()->create();
    $currentWorkspace = Workspace::factory()->create();
    [$plain, $hash] = WorkspaceInvitation::generateToken();
    WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'token' => $hash,
    ]);
    $user = User::factory()->create([
        'email' => 'guest@example.com',
        'current_workspace_id' => $currentWorkspace->id,
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $currentWorkspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('workspace.invitation', $plain))->assertRedirect(route('dashboard'));

    $this->assertTrue($user->fresh()->isMemberOfWorkspace($workspace->id));
    $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
});

test('guest invitee sees acceptance page', function () {
    $this->withoutVite();

    $workspace = Workspace::factory()->create();
    [$plain, $hash] = WorkspaceInvitation::generateToken();
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'token' => $hash]);

    $this->get(route('workspace.invitation', $plain))->assertOk();
});

test('invalid token redirects home with error', function () {
    $this->get(route('workspace.invitation', 'nope'))
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors();
});

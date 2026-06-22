<?php

use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('user can create a workspace and becomes owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspaces.store'), ['name' => 'New Co'])
        ->assertRedirect(route('dashboard'));

    $workspace = Workspace::where('name', 'New Co')->firstOrFail();
    $this->assertSame(WorkspaceRole::Owner, $user->getMembershipForWorkspace($workspace->id)->role);
    $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
});

test('user cannot switch to a workspace they do not belong to', function () {
    $user = User::factory()->create();
    $other = Workspace::factory()->create();

    $this->actingAs($user)->post(route('workspaces.switch'), ['workspace_id' => $other->id])
        ->assertSessionHasErrors();

    $this->assertNull($user->fresh()->current_workspace_id);
});

test('user can switch to their workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    $this->actingAs($user)->post(route('workspaces.switch'), ['workspace_id' => $workspace->id])
        ->assertRedirect();

    $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
});

test('switching workspace redirects away from workspace-scoped detail pages', function () {
    $user = User::factory()->create();
    $current = Workspace::factory()->create();
    $next = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $current->id, 'user_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $next->id, 'user_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $current->id])->save();
    $post = Post::factory()->for($current)->create(['author_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('posts.show', $post))
        ->post(route('workspaces.switch'), ['workspace_id' => $next->id])
        ->assertRedirect(route('dashboard'));

    $this->assertSame($next->id, $user->fresh()->current_workspace_id);
});

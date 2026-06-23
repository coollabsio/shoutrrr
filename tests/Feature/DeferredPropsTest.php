<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

test('dashboard omits the posts feed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->missing('posts')
        );
});

test('posts index defers the list', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->missing('posts')               // deferred — absent on initial render
            ->has('filters')                 // small scalar props stay inline
            ->has('counts')
            ->loadDeferredProps(fn ($reload) => $reload->has('posts.data'))
        );
});

test('calendar defers month posts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->get(route('calendar.month', ['yyyymm' => now()->format('Y-m')]))
        ->assertInertia(fn ($page) => $page
            ->has('yyyymm')                  // small scalar the grid needs immediately
            ->missing('posts')               // deferred — absent on initial render
            ->loadDeferredProps(fn ($reload) => $reload->has('posts'))
        );
});

test('queue defers slots', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->get(route('queue.show'))
        ->assertInertia(fn ($page) => $page
            ->has('timezone')                // page chrome needs the timezone label
            ->has('canManage')               // small scalar stays inline
            ->missing('slots')               // deferred — absent on initial render
            ->loadDeferredProps(fn ($reload) => $reload->has('slots'))
        );
});

test('workspace members defers the member list', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->get(route('settings.workspace.members'))
        ->assertInertia(fn ($page) => $page
            ->has('canManage')               // small scalar stays inline
            ->has('availableRoles')          // small scalar stays inline
            ->missing('members')             // deferred — absent on initial render
            ->loadDeferredProps(fn ($reload) => $reload->has('members'))
        );
});

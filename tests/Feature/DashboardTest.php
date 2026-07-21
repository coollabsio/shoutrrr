<?php

use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMention;
use Illuminate\Support\Facades\Context;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the recent feed exposes full row data including targets', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    PostTarget::factory()->for($post)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->missing('posts')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.0.targets')
                ->has('posts.0.published_at')));
});

test('the recent feed exposes attached media for a row thumbnail', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    PostTarget::factory()->for($post)->create();
    PostMedia::factory()->for($post)->create([
        'workspace_id' => $workspace->id,
        'position' => 0,
        'kind' => 'image',
    ]);
    PostMedia::factory()->for($post)->video()->create([
        'workspace_id' => $workspace->id,
        'position' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('posts.0.media_count', 2)
                ->where('posts.0.media_preview.kind', 'image')
                ->has('posts.0.media_preview.url')));
});

test('a video-only post exposes no preview url so the list shows an icon tile', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    PostTarget::factory()->for($post)->create();
    PostMedia::factory()->for($post)->video()->create([
        'workspace_id' => $workspace->id,
        'position' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('posts.0.media_count', 1)
                ->where('posts.0.media_preview.kind', 'video')
                ->where('posts.0.media_preview.url', null)));
});

test('the dashboard includes saved workspace mentions for the composer', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    WorkspaceMention::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '@saved',
        'handles' => ['x' => '@saved_x'],
    ]);
    WorkspaceMention::factory()->create(['name' => '@foreign']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('savedMentions', 1)
            ->where('savedMentions.0.name', '@saved'));
});

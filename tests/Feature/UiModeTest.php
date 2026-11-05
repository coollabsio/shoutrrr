<?php

use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Enums\UiMode;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Inertia\Testing\AssertableInertia as Assert;

test('new users default to agentic and home lands there', function () {
    $user = User::factory()->create();

    expect($user->uiMode())->toBe(UiMode::Agentic);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('agentic.home'));
});

test('an explicit classic preference lands on the composer', function () {
    $user = User::factory()->create(['ui_mode' => UiMode::Classic]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

test('classic users still sign in to the composer', function () {
    $user = User::factory()->create(['ui_mode' => UiMode::Classic]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
});

test('signed-in users who open a guest page land on their mode home', function (UiMode $mode, string $home) {
    $user = User::factory()->create(['ui_mode' => $mode]);

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route($home));
})->with([
    'agentic' => [UiMode::Agentic, 'agentic.home'],
    'classic' => [UiMode::Classic, 'dashboard'],
]);

test('the mode switch persists and redirects to that home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('ui-mode.update'), ['mode' => 'classic'])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->uiMode())->toBe(UiMode::Classic);

    $this->actingAs($user->fresh())
        ->put(route('ui-mode.update'), ['mode' => 'agentic'])
        ->assertRedirect(route('agentic.home'));

    expect($user->fresh()->uiMode())->toBe(UiMode::Agentic);
});

test('an unknown mode is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('agentic.home'))
        ->put(route('ui-mode.update'), ['mode' => 'something-else'])
        ->assertSessionHasErrors('mode');

    expect($user->fresh()->uiMode())->toBe(UiMode::Agentic);
});

test('guests cannot change mode', function () {
    $this->put(route('ui-mode.update'), ['mode' => 'classic'])->assertRedirect(route('login'));
});

test('agentic home shows setup until sync or an assistant has posted', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('agentic.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agentic/home')
            ->where('auth.user.ui_mode', 'agentic')
            ->where('sync.total', 0)
            ->where('accounts', [])
            ->where('sync.suggestion', null)
            ->where('recent_posts', null)
            ->missing('mcp')
        );
});

test('agentic home shows recent posts once an assistant or sync has posted', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $composed = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $user->id,
        'status' => PostStatus::Published,
        'created_at' => now()->subHour(),
    ]);
    Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('agentic.home'))
        ->assertInertia(fn (Assert $page) => $page->where('recent_posts', null));

    $agentPost = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $user->id,
        'origin' => PostOrigin::Agent,
        'status' => PostStatus::Scheduled,
    ]);

    $this->actingAs($user)
        ->get(route('agentic.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recent_posts', 2)
            ->where('recent_posts.0.id', $agentPost->id)
            ->where('recent_posts.0.origin', 'agent')
            ->where('recent_posts.1.id', $composed->id)
        );
});

test('agentic settings show the MCP endpoint and REST API examples', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('agentic-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/agentic')
            ->where('mcp.endpoint', url('/mcp'))
            ->where('api.base_url', url('/api/v1'))
            ->where('agent_connected', false)
            ->has('api.publish_example')
            ->has('api.schedule_example')
        );
});

test('classic dashboard still renders for a classic preference', function () {
    $user = User::factory()->create(['ui_mode' => UiMode::Classic]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.user.ui_mode', 'classic')
        );
});

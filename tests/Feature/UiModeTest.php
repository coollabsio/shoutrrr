<?php

use App\Enums\UiMode;
use App\Models\AgentIntent;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\UsageOperation;
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

test('agentic home shows setup, sync, and recent activity', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    AgentIntent::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'idempotency_key' => 'home-intent',
    ]);

    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'operation' => UsageOperation::MCP_REQUEST,
        'succeeded' => true,
        'meta' => ['status' => 200],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('agentic.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agentic/home')
            ->where('auth.user.ui_mode', 'agentic')
            ->where('mcp.endpoint', url('/mcp'))
            ->where('sync.total', 0)
            ->where('setup.accounts', 0)
            ->where('setup.agent_connected', false)
            ->has('activity', 2)
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

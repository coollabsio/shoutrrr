<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Inertia\Testing\AssertableInertia;

test('dashboard shares onboarding prop for the current workspace owner', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create([
        'current_workspace_id' => $workspace->id,
        'email_verified_at' => now(),
    ]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('onboarding.welcomed', false)
            ->where('onboarding.dismissed', false)
            ->where('onboarding.complete', false)
            ->has('onboarding.steps', 5)
        );
});

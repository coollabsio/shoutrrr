<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

test('shell props expose accounts, sets, and limits on every page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    ConnectedAccount::factory()->for($workspace)->needsAttention()->create();
    AccountSet::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('shell.accounts', 1)
            ->where('shell.accounts.0.status', 'needs_attention')
            ->where('shell.accounts.0.max_video_duration_seconds', 140)
            ->has('shell.sets', 1)
            ->has('shell.limits')
        );
});

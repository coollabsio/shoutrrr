<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
});

test('analytics rollup is cached per workspace and range', function (): void {
    $workspaceId = $this->workspace->id;

    ConnectedAccount::factory()->create([
        'workspace_id' => $workspaceId,
        'platform' => Platform::X->value,
    ]);

    $this->actingAs($this->user)->get('/analytics?days=90')->assertOk();

    expect(Cache::has("analytics-rollup:{$workspaceId}:90"))->toBeTrue();

    // Data created after the first request must NOT appear until the cache expires.
    ConnectedAccount::factory()->create([
        'workspace_id' => $workspaceId,
        'platform' => Platform::Bluesky->value,
    ]);

    $this->actingAs($this->user)->get('/analytics?days=90')
        ->assertInertia(fn ($page) => $page->has('accounts', 1));

    // A different range is a distinct cache entry.
    $this->actingAs($this->user)->get('/analytics?days=30')->assertOk();
    expect(Cache::has("analytics-rollup:{$workspaceId}:30"))->toBeTrue();
});

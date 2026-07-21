<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

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

test('manual metrics refresh route is enabled', function () {
    // Was intentionally unwired (dead controller). Now wired up so a post that
    // has aged out of automatic polling can still be refreshed on demand — see
    // PostMetricsRefreshTest.php for the full request/auth/response coverage.
    expect(Route::has('posts.metrics.refresh'))->toBeTrue();
});

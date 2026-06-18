<?php

use App\Enums\MetricsStatus;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
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

test('analytics page renders with accounts and range', function (): void {
    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('analytics/index', shouldExist: false)
            ->has('accounts')
            ->has('posts')
            ->has('comparison')
            ->where('rangeDays', 90));
});

test('range is clamped to 365', function (): void {
    $this->actingAs($this->user)
        ->get(route('analytics.index', ['days' => 5000]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rangeDays', 365));
});

test('analytics 404s when metrics disabled', function (): void {
    config(['metrics.enabled' => false]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertNotFound();
});

test('comparison collapses to single ranked list when fewer than 10 eligible posts', function (): void {
    // Create 3 published posts with at least one ok PostTarget each.
    $posts = Post::factory(3)->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published->value,
        'published_at' => now()->subDay(),
    ]);

    // Give each post a different engagement total so ranking is deterministic.
    $engagements = [10, 30, 20];
    foreach ($posts as $i => $post) {
        PostTarget::factory()->create([
            'post_id' => $post->id,
            'metrics_status' => MetricsStatus::Ok->value,
            'likes' => $engagements[$i],
            'comments' => 0,
            'reposts' => 0,
        ]);
    }

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('analytics/index', shouldExist: false)
            ->where('comparison.bottom', [])
            ->has('comparison.top', 3)
            ->where('comparison.top.0.engagement', 30)
            ->where('comparison.top.1.engagement', 20)
            ->where('comparison.top.2.engagement', 10));
});

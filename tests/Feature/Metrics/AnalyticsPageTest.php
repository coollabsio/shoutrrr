<?php

use App\Enums\MetricsStatus;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;

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

test('the follower series is downsampled to one point per day', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create();

    // Three readings on the same day collapse to the last one (30).
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->subDay()->setTime(8, 0), 'followers' => 10]);
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->subDay()->setTime(14, 0), 'followers' => 20]);
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->subDay()->setTime(20, 0), 'followers' => 30]);
    // One reading the next day.
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->setTime(9, 0), 'followers' => 40]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts.0.series', 2)
            ->where('accounts.0.series.0.followers', 30)
            ->where('accounts.0.series.1.followers', 40)
            ->where('accounts.0.latest_followers', 40));
});

test('the analytics rollup reflects newly captured metrics on the next page load', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create();
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->subHours(2), 'followers' => 100]);

    $this->actingAs($this->user)->get(route('analytics.index'))->assertOk();

    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now(), 'followers' => 999]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertInertia(fn ($page) => $page->where('accounts.0.latest_followers', 999));
});

test('disconnecting an account removes it from cached analytics', function (): void {
    $keptAccount = ConnectedAccount::factory()->for($this->workspace)->create();
    $disconnectedAccount = ConnectedAccount::factory()->for($this->workspace)->create();

    AccountMetric::factory()->create(['connected_account_id' => $keptAccount->id, 'captured_at' => Date::now(), 'followers' => 100]);
    AccountMetric::factory()->create(['connected_account_id' => $disconnectedAccount->id, 'captured_at' => Date::now(), 'followers' => 200]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('accounts', 2));

    $this->actingAs($this->user)
        ->delete(route('accounts.destroy', $disconnectedAccount))
        ->assertRedirect(route('accounts.index'));

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $keptAccount->id));
});

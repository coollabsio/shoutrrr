<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\InstanceSettings;
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

test('the summary reports total followers with a window delta', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create();

    // Two readings inside the default 90-day window: 100 → 130 = +30.
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->subDays(10), 'followers' => 100]);
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now(), 'followers' => 130]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.account_count', 1)
            ->where('summary.followers.value', 130)
            ->where('summary.followers.delta', 30)
            ->where('accounts.0.followers_delta', 30));
});

test('the follower delta is null without two comparable readings', function (): void {
    $account = ConnectedAccount::factory()->for($this->workspace)->create();
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now(), 'followers' => 100]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.followers.value', 100)
            ->where('summary.followers.delta', null)
            ->where('accounts.0.followers_delta', null));
});

test('the engagement summary compares against the previous window', function (): void {
    // Previous window (10–20 days ago): one post with 40 engagement.
    $previous = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published->value,
        'published_at' => Date::now()->subDays(15),
    ]);
    PostTarget::factory()->create([
        'post_id' => $previous->id,
        'metrics_status' => MetricsStatus::Ok->value,
        'likes' => 40, 'comments' => 0, 'reposts' => 0,
    ]);

    // Current window (last 10 days): one post with 100 engagement.
    $current = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published->value,
        'published_at' => Date::now()->subDay(),
    ]);
    PostTarget::factory()->create([
        'post_id' => $current->id,
        'metrics_status' => MetricsStatus::Ok->value,
        'likes' => 70, 'comments' => 20, 'reposts' => 10,
    ]);

    $this->actingAs($this->user)
        ->get(route('analytics.index', ['days' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.engagement.value', 100)
            ->where('summary.engagement.delta', 60)
            ->where('summary.posts.value', 1)
            ->where('summary.posts.delta', 0));
});

test('deltas are null when the previous window has no baseline posts', function (): void {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published->value,
        'published_at' => Date::now()->subDay(),
    ]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'metrics_status' => MetricsStatus::Ok->value,
        'likes' => 50, 'comments' => 0, 'reposts' => 0,
    ]);

    $this->actingAs($this->user)
        ->get(route('analytics.index', ['days' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.engagement.value', 50)
            ->where('summary.engagement.delta', null)
            ->where('summary.posts.delta', null));
});

test('engagement delta is null when the previous window has posts but no captured metrics', function (): void {
    // Previous window has a post, but its target never captured Ok metrics — so
    // there is no engagement baseline even though a post exists.
    $previous = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published->value,
        'published_at' => Date::now()->subDays(15),
    ]);
    PostTarget::factory()->create([
        'post_id' => $previous->id,
        'metrics_status' => MetricsStatus::Failed->value,
        'likes' => 0, 'comments' => 0, 'reposts' => 0,
    ]);

    $current = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published->value,
        'published_at' => Date::now()->subDay(),
    ]);
    PostTarget::factory()->create([
        'post_id' => $current->id,
        'metrics_status' => MetricsStatus::Ok->value,
        'likes' => 50, 'comments' => 0, 'reposts' => 0,
    ]);

    $this->actingAs($this->user)
        ->get(route('analytics.index', ['days' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.engagement.value', 50)
            // No measured post last window → no fake "+50" spike.
            ->where('summary.engagement.delta', null)
            // The post count baseline still exists (one post each window).
            ->where('summary.posts.delta', 0));
});

test('analytics polling settings are keyed by platform enum values', function (): void {
    app(InstanceSettings::class)->update([
        'post_metrics_polling_enabled' => [
            Platform::X->value => false,
            Platform::Bluesky->value => true,
            Platform::LinkedIn->value => true,
        ],
        'account_metrics_polling_enabled' => [
            Platform::X->value => true,
            Platform::Bluesky->value => false,
            Platform::LinkedIn->value => true,
        ],
    ]);

    $expectedPlatforms = collect(Platform::cases())
        ->mapWithKeys(fn (Platform $platform): array => [$platform->value => true])
        ->all();

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('polling.post_metrics_enabled', [
                ...$expectedPlatforms,
                Platform::X->value => false,
            ])
            ->where('polling.account_metrics_enabled', [
                ...$expectedPlatforms,
                Platform::Bluesky->value => false,
            ]));
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

test('discord accounts are excluded from follower analytics', function (): void {
    $x = ConnectedAccount::factory()->for($this->workspace)->create([
        'platform' => Platform::X,
    ]);
    ConnectedAccount::factory()->for($this->workspace)->discord()->create();

    AccountMetric::factory()->create([
        'connected_account_id' => $x->id,
        'captured_at' => Date::now(),
        'followers' => 42,
    ]);

    $this->actingAs($this->user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $x->id)
            ->where('accounts.0.platform', Platform::X->value));
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

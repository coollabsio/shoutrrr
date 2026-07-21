<?php

declare(strict_types=1);

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\DummyAnalyticsSeeder;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create(['slug' => 'test-workspace']);
    $this->user = User::factory()->create([
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->forceFill(['owner_id' => $this->user->id])->save();

    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);

    Context::add('workspace_id', $this->workspace->id);
});

test('dummy analytics seeder covers follower platforms and omits Discord', function (): void {
    $this->seed(DummyAnalyticsSeeder::class);

    $platforms = collect(DummyAnalyticsSeeder::accountSpecs())
        ->map(fn (array $spec): string => $spec['platform']->value)
        ->all();

    $accounts = ConnectedAccount::query()
        ->where('workspace_id', $this->workspace->id)
        ->get();

    $posts = Post::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('base_text', 'like', '%[dummy-analytics]%')
        ->get();

    $historyPoints = DummyAnalyticsSeeder::HISTORY_DAYS + 1;

    expect($platforms)->not->toContain(Platform::Discord->value)
        ->and($accounts->pluck('platform')->map->value->all())
        ->not->toContain(Platform::Discord->value)
        ->and($accounts)->toHaveCount(DummyAnalyticsSeeder::platformCount())
        ->and($posts)->toHaveCount(DummyAnalyticsSeeder::POST_COUNT)
        ->and(
            AccountMetric::query()
                ->whereHas('account', fn ($q) => $q->where('workspace_id', $this->workspace->id))
                ->count(),
        )->toBe($historyPoints * DummyAnalyticsSeeder::platformCount())
        ->and(
            PostTarget::query()
                ->whereIn('post_id', $posts->pluck('id'))
                ->where('metrics_status', MetricsStatus::Ok->value)
                ->count(),
        )->toBe(DummyAnalyticsSeeder::POST_COUNT * DummyAnalyticsSeeder::platformCount())
        ->and(
            PostTarget::query()
                ->whereIn('post_id', $posts->pluck('id'))
                ->pluck('platform')
                ->map(fn ($p): string => $p->value)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        )->toEqual(collect($platforms)->sort()->values()->all())
        ->and($accounts->every->isDisabled())->toBeTrue();
});

test('dummy analytics seeder is idempotent for marked posts and account metrics', function (): void {
    $this->seed(DummyAnalyticsSeeder::class);

    $firstPosts = Post::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('base_text', 'like', '%[dummy-analytics]%')
        ->count();
    $firstMetrics = AccountMetric::query()
        ->whereHas('account', fn ($q) => $q->where('workspace_id', $this->workspace->id))
        ->count();

    $this->seed(DummyAnalyticsSeeder::class);

    expect(
        Post::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('base_text', 'like', '%[dummy-analytics]%')
            ->count(),
    )->toBe($firstPosts)
        ->and(
            AccountMetric::query()
                ->whereHas('account', fn ($q) => $q->where('workspace_id', $this->workspace->id))
                ->count(),
        )->toBe($firstMetrics);
});

test('dummy analytics seeder disables the legacy demo Discord account only', function (): void {
    $demoDiscord = ConnectedAccount::factory()->for($this->workspace)->discord()->create([
        'remote_account_id' => 'discord-webhook-analytics-1',
    ]);
    $realDiscord = ConnectedAccount::factory()->for($this->workspace)->discord()->create([
        'remote_account_id' => 'real-discord-webhook',
    ]);

    $this->seed(DummyAnalyticsSeeder::class);

    expect($demoDiscord->fresh()->isDisabled())->toBeTrue()
        ->and($realDiscord->fresh()->isDisabled())->toBeFalse();
});

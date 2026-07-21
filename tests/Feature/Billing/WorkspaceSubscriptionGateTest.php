<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\UsageCategory;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Services\Usage\UsageRecorder;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Illuminate\Support\Facades\Date;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.monthly_x_budget_cents' => 500,
    ]);
});

/**
 * Seed X publish usage the same way UsageRecorder does: one usage event per post
 * (the gate counts events within the billing-anchored period for subscribed
 * workspaces) plus the calendar-month counter (the fallback for unsubscribed ones).
 */
function recordXPosts(Workspace $workspace, int $count = 1, mixed $occurredAt = null): void
{
    $now = Date::now();
    $occurredAt ??= $now;
    $costMicrousd = 15_000;

    UsageEvent::factory()->count($count)->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'quota_weight' => 1,
        'cost_weight_microusd' => $costMicrousd,
        'succeeded' => true,
        'occurred_at' => $occurredAt,
    ]);

    $counter = UsagePeriodCounter::query()->firstOrCreate(
        [
            'workspace_id' => $workspace->id,
            'period_start' => $now->copy()->startOfMonth()->toDateString(),
            'category' => UsageCategory::Publish->value,
            'platform' => Platform::X->value,
            'operation' => UsageOperation::POST,
        ],
        [
            'period_end' => $now->copy()->endOfMonth()->toDateString(),
        ],
    );

    $counter->increment('event_count', $count);
    $counter->increment('total_quota', $count);
    $counter->increment('total_cost_microusd', $count * $costMicrousd);
}

test('publishing is free and unlimited when self hosted mode disables billing', function () {
    config(['subscriptions.enabled' => false]);
    $workspace = Workspace::factory()->create();
    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->isEnabled())->toBeFalse()
        ->and($gate->canPublish($workspace))->toBeTrue()
        ->and($gate->canPublishX($workspace))->toBeTrue()
        ->and($gate->remainingXPosts($workspace))->toBe(PHP_INT_MAX);
});

function subscribedWorkspace(array $attributes = []): Workspace
{
    if (Workspace::query()->count() === 0) {
        Workspace::factory()->create();
    }

    $workspace = Workspace::factory()->create(array_merge(['stripe_id' => 'cus_test_123'], $attributes));

    Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'default',
        'stripe_id' => 'sub_test_'.fake()->unique()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => config('subscriptions.stripe_price_id'),
        'quantity' => 1,
    ]);

    return $workspace;
}

test('additional cloud workspaces must have an active subscription to publish', function () {
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();

    expect(app(WorkspaceSubscriptionGate::class)->canPublish($workspace))->toBeFalse();
});

test('x publishing quota is five dollars worth of worst case monthly requests', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();
    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->monthlyXPostLimit($workspace))->toBe(25)
        ->and($gate->remainingXPosts($workspace))->toBe(25);

    recordXPosts($workspace, 24);

    expect($gate->remainingXPosts($workspace))->toBe(1)
        ->and($gate->canPublishX($workspace))->toBeTrue();

    recordXPosts($workspace);

    expect($gate->remainingXPosts($workspace))->toBe(0)
        ->and($gate->canPublishX($workspace))->toBeFalse();

    Date::setTestNow();
});

test('x publishing stops when cumulative x cost reaches the monthly budget', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();

    // Cost is recomputed from the pricing map, not the stored column, so the
    // draining operation must be one the current map actually prices (X reads
    // via REPLIES_FETCH cost $0.005/unit: 998 * 5_000 = 4_990_000 microusd).
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::ExternalApi->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::REPLIES_FETCH,
        'quota_weight' => 998,
        'cost_weight_microusd' => 4_990_000,
        'succeeded' => true,
        'occurred_at' => Date::now(),
    ]);

    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->currentXPostUsage($workspace))->toBe(0)
        ->and($gate->currentXCostMicrousd($workspace))->toBe(4_990_000)
        ->and($gate->remainingXBudgetMicrousd($workspace))->toBe(10_000)
        ->and($gate->canPublishX($workspace))->toBeFalse();

    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'quota_weight' => 1,
        'cost_weight_microusd' => 15_000,
        'succeeded' => true,
        'occurred_at' => Date::now(),
    ]);

    expect($gate->remainingXBudgetMicrousd($workspace))->toBe(0)
        ->and($gate->canPublishX($workspace))->toBeFalse();

    Date::setTestNow();
});

test('x publishing reserves enough budget for url bearing tweets', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();

    // Cost is recomputed from the pricing map, not the stored column, so the
    // draining operation must be one the current map actually prices (X reads
    // via REPLIES_FETCH cost $0.005/unit: 970 * 5_000 = 4_850_000 microusd).
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::ExternalApi->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::REPLIES_FETCH,
        'quota_weight' => 970,
        'cost_weight_microusd' => 4_850_000,
        'succeeded' => true,
        'occurred_at' => Date::now(),
    ]);

    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->remainingXPosts($workspace))->toBe(25)
        ->and($gate->remainingXBudgetMicrousd($workspace))->toBe(150_000)
        ->and($gate->canPublishX($workspace))->toBeFalse();

    Date::setTestNow();
});

test('a failed x publish does not consume quota because the job no longer pre-charges', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Publishing,
    ]);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
    ]);

    $connectorCalls = 0;
    $connector = new class($connectorCalls) implements PublishConnector
    {
        public function __construct(private int &$calls) {}

        public function publish(PublishContext $context): PublishResult
        {
            $this->calls++;

            return PublishResult::failure(ErrorKind::Validation, 'upstream failed', 400);
        }

        public function delete(PostTarget $target, array $credentials): void {}
    };

    app()->instance(PublishConnectorRegistry::class, new class($connector) extends PublishConnectorRegistry
    {
        public function __construct(private PublishConnector $connector) {}

        public function for(Platform $platform): PublishConnector
        {
            return $this->connector;
        }
    });

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
        app(WorkspaceSubscriptionGate::class),
    );

    // Quota is now driven by the metering counters the publish connector increments
    // on success. A failed publish meters nothing, so the full quota remains.
    expect($connectorCalls)->toBe(1)
        ->and(app(WorkspaceSubscriptionGate::class)->remainingXPosts($workspace))->toBe(25);

    Date::setTestNow();
});

test('x publishing stops before calling the connector when quota is exhausted', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();
    recordXPosts($workspace, 25);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Publishing,
    ]);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
    ]);

    app()->instance(PublishConnectorRegistry::class, new class extends PublishConnectorRegistry
    {
        public function for(Platform $platform): PublishConnector
        {
            throw new RuntimeException('connector should not be called');
        }
    });

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
        app(WorkspaceSubscriptionGate::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::BillingRequired)
        ->and($target->error_message)->toBe('Monthly X publishing quota exceeded. Upgrade or wait for the next billing period.');

    Date::setTestNow();
});

test('x publishing reports a budget failure when the quota still has room', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();

    // Non-publish X calls drain the shared monthly API budget without using any
    // of the 333 post quota, so the failure must name the budget, not the quota.
    // Cost is recomputed from the pricing map, not the stored column, so the
    // draining operation must be one the current map actually prices (X reads
    // via REPLIES_FETCH cost $0.005/unit: 998 * 5_000 = 4_990_000 microusd).
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::ExternalApi->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::REPLIES_FETCH,
        'quota_weight' => 998,
        'cost_weight_microusd' => 4_990_000,
        'succeeded' => true,
        'occurred_at' => Date::now(),
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Publishing,
    ]);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
    ]);

    app()->instance(PublishConnectorRegistry::class, new class extends PublishConnectorRegistry
    {
        public function for(Platform $platform): PublishConnector
        {
            throw new RuntimeException('connector should not be called');
        }
    });

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
        app(WorkspaceSubscriptionGate::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::BillingRequired)
        ->and($target->error_message)->toBe('Monthly X API budget exceeded. Upgrade or wait for the next billing period.');

    Date::setTestNow();
});

test('url bearing tweets count toward the x post quota', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();

    recordXPosts($workspace, 2);

    UsageEvent::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST_WITH_URL,
        'quota_weight' => 1,
        'cost_weight_microusd' => 200_000,
        'succeeded' => true,
        'occurred_at' => Date::now(),
    ]);

    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->currentXPostUsage($workspace))->toBe(5)
        ->and($gate->remainingXPosts($workspace))->toBe(20);

    Date::setTestNow();
});

test('url bearing tweets count toward the x post quota without a subscription', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = Workspace::factory()->create();
    $now = Date::now();

    UsagePeriodCounter::query()->create([
        'workspace_id' => $workspace->id,
        'period_start' => $now->copy()->startOfMonth()->toDateString(),
        'period_end' => $now->copy()->endOfMonth()->toDateString(),
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST_WITH_URL,
        'event_count' => 4,
        'total_quota' => 4,
        'total_cost_microusd' => 800_000,
    ]);

    expect(app(WorkspaceSubscriptionGate::class)->currentXPostUsage($workspace))->toBe(4);

    Date::setTestNow();
});

test('x quota period is anchored to the subscription date, not the calendar month', function () {
    Date::setTestNow('2026-06-10 12:00:00');
    $workspace = subscribedWorkspace(); // subscription created June 10

    // Posts from before the current cycle started must not count.
    recordXPosts($workspace, 5, Date::now()->subDays(3));

    Date::setTestNow('2026-07-05 12:00:00'); // still inside the June 10 → July 10 cycle
    recordXPosts($workspace, 2);

    $gate = app(WorkspaceSubscriptionGate::class);

    // Only the 2 posts since June 10 count, even though 5 happened in June and
    // a calendar-month reset on July 1 would have shown zero usage.
    expect($gate->currentXPostUsage($workspace))->toBe(2)
        ->and($gate->remainingXPosts($workspace))->toBe(23);

    Date::setTestNow('2026-07-11 12:00:00'); // next cycle started July 10
    expect($gate->currentXPostUsage($workspace))->toBe(0)
        ->and($gate->remainingXPosts($workspace))->toBe(25);

    Date::setTestNow();
});

test('non positive x publish pricing means unlimited x publishing', function () {
    config([
        'usage_pricing.platforms.x.resources.post_create.unit_cost_usd' => 0,
        'usage_pricing.platforms.x.resources.post_create_with_url.unit_cost_usd' => 0,
    ]);
    $workspace = subscribedWorkspace();
    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->monthlyXPostLimit($workspace))->toBeNull()
        ->and($gate->remainingXPosts($workspace))->toBe(PHP_INT_MAX)
        ->and($gate->canPublishX($workspace))->toBeTrue();
});

it('treats an unlimited override like the initial workspace', function (): void {
    config()->set('subscriptions.enabled', true);
    $workspace = Workspace::factory()->create(['is_initial' => false]);
    app(InstanceSettings::class)->setXWorkspaceBudget($workspace->id, 'unlimited');

    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->monthlyXBudgetMicrousd($workspace))->toBeNull()
        ->and($gate->monthlyXPostLimit($workspace))->toBeNull()
        ->and($gate->remainingXPosts($workspace))->toBe(PHP_INT_MAX)
        ->and($gate->remainingXBudgetMicrousd($workspace))->toBe(PHP_INT_MAX)
        ->and($gate->canPublishX($workspace))->toBeTrue();
});

it('applies a finite per-workspace override to the budget and post limit', function (): void {
    config()->set('subscriptions.enabled', true);
    config()->set('subscriptions.monthly_x_budget_cents', 500);
    $workspace = Workspace::factory()->create(['is_initial' => false]);
    app(InstanceSettings::class)->setXWorkspaceBudget($workspace->id, 2000); // $20

    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->monthlyXBudgetMicrousd($workspace))->toBe(20_000_000)
        ->and($gate->monthlyXPostLimit($workspace))->toBe(100); // floor(20 / 0.20)
});

it('recomputes X cost from the current pricing map, ignoring stale stored cost', function (): void {
    config()->set('subscriptions.enabled', true);
    app(InstanceSettings::class)->update(['usage_tracking_enabled' => true]);
    $workspace = Workspace::factory()->create(['is_initial' => false]);

    app(UsageRecorder::class)->record(
        category: UsageCategory::ExternalApi,
        operation: UsageOperation::MEDIA_UPLOAD,
        workspaceId: $workspace->id,
        platform: Platform::X,
        quotaWeight: 3,
        succeeded: true,
    );

    // Even if a stale row carried a nonzero stored cost, the gate recomputes 0
    // for the now-unmapped MEDIA_UPLOAD operation.
    expect(app(WorkspaceSubscriptionGate::class)->currentXCostMicrousd($workspace))->toBe(0);
});

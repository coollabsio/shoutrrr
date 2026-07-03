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
use App\Support\UsageOperation;
use Illuminate\Support\Facades\Date;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.monthly_x_budget_cents' => 500,
        'subscriptions.x_post_cost_cents' => 1.5,
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

    UsageEvent::factory()->count($count)->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'quota_weight' => 1,
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

test('x publishing quota is five dollars worth of monthly requests', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();
    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->monthlyXPostLimit())->toBe(333)
        ->and($gate->remainingXPosts($workspace))->toBe(333);

    recordXPosts($workspace, 332);

    expect($gate->remainingXPosts($workspace))->toBe(1)
        ->and($gate->canPublishX($workspace))->toBeTrue();

    recordXPosts($workspace);

    expect($gate->remainingXPosts($workspace))->toBe(0)
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
        ->and(app(WorkspaceSubscriptionGate::class)->remainingXPosts($workspace))->toBe(333);

    Date::setTestNow();
});

test('x publishing stops before calling the connector when quota is exhausted', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    $workspace = subscribedWorkspace();
    recordXPosts($workspace, 333);
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
        ->and($gate->remainingXPosts($workspace))->toBe(331);

    Date::setTestNow('2026-07-11 12:00:00'); // next cycle started July 10
    expect($gate->currentXPostUsage($workspace))->toBe(0)
        ->and($gate->remainingXPosts($workspace))->toBe(333);

    Date::setTestNow();
});

test('a non positive x post cost means unlimited x publishing', function () {
    config(['subscriptions.x_post_cost_cents' => 0]);
    $workspace = subscribedWorkspace();
    $gate = app(WorkspaceSubscriptionGate::class);

    expect($gate->monthlyXPostLimit())->toBeNull()
        ->and($gate->remainingXPosts($workspace))->toBe(PHP_INT_MAX)
        ->and($gate->canPublishX($workspace))->toBeTrue();
});

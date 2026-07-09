<?php

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Enums\WorkspaceRole;
use App\Models\UsagePeriodCounter;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\UsageOperation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Subscription;
use Symfony\Component\Process\Process;

afterEach(function () {
    Date::setTestNow();
});

test('billing routes exist but are inactive on self hosted instances', function () {
    expect(config('subscriptions.enabled'))->toBeFalse()
        ->and(Route::has('billing.index'))->toBeTrue()
        ->and(Route::has('billing.checkout'))->toBeTrue()
        ->and(Route::has('billing.portal'))->toBeTrue();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertNotFound();
});

test('billing page is routed through workspace settings', function () {
    expect(route('billing.index', absolute: false))->toBe('/settings/workspace/subscription');
});

test('billing defaults to disabled when self hosted is unset', function () {
    $process = new Process([
        PHP_BINARY,
        '-r',
        'require "vendor/autoload.php"; putenv("SELF_HOSTED"); unset($_ENV["SELF_HOSTED"], $_SERVER["SELF_HOSTED"]); $config = require "config/subscriptions.php"; echo $config["enabled"] ? "enabled" : "disabled";',
    ], base_path(), ['SELF_HOSTED' => false]);

    $process->mustRun();

    expect($process->getOutput())->toBe('disabled');
});

test('billing page does not show portal management for a customer without a subscription', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_id' => $user->id,
        'stripe_id' => 'cus_test_without_subscription',
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/subscription')
            ->where('subscribed', false)
            ->where('canManageSubscription', false)
            ->where('canAccessPortal', true));
});

test('billing page shows current month x budget usage', function () {
    Date::setTestNow('2026-06-15 12:00:00');
    config([
        'subscriptions.enabled' => true,
        'subscriptions.monthly_x_budget_cents' => 500,
        'subscriptions.x_post_cost_cents' => 1.5,
    ]);
    Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'event_count' => 12,
        'total_quota' => 12,
        'total_cost_microusd' => 180_000,
    ]);
    // A prior month must not leak into the current-period count.
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'event_count' => 99,
        'total_quota' => 99,
        'total_cost_microusd' => 1_485_000,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/subscription')
            ->where('monthlyXBudgetMicrousd', 5_000_000)
            ->where('monthlyXBudgetUsedMicrousd', 180_000)
            ->where('monthlyXBudgetRemainingMicrousd', 4_820_000));
});

test('portal is unavailable for a workspace that never became a stripe customer', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->post(route('billing.portal'))
        ->assertNotFound();
});

test('checkout is rejected when the workspace already has an active subscription', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_id' => $user->id,
        'stripe_id' => 'cus_test_active',
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'default',
        'stripe_id' => 'sub_test_active',
        'stripe_status' => 'active',
        'stripe_price' => config('subscriptions.stripe_price_id'),
        'quantity' => 1,
    ]);

    $this->actingAs($user)
        ->post(route('billing.checkout'))
        ->assertSessionHas('error', 'This workspace already has an active subscription.');
});

test('workspace stripe customer email comes from its owner', function () {
    $user = User::factory()->create(['email' => 'test2@example.com']);
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

    expect($workspace->stripeEmail())->toBe('test2@example.com');
});

test('members without the billing permission cannot reach any billing action', function (string $method, string $route) {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_id' => User::factory()->create()->id,
        // Present so `portal` would otherwise pass its hasStripeId() gate: this
        // proves authorization runs before the Stripe customer is handed over.
        'stripe_id' => 'cus_test_member_denied',
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->{$method}(route($route))
        ->assertForbidden();
})->with([
    'index' => ['get', 'billing.index'],
    'checkout' => ['post', 'billing.checkout'],
    'portal' => ['post', 'billing.portal'],
]);

test('a user with no membership in the current workspace cannot reach billing', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertForbidden();
});

test('admins may manage billing', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Admin,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertOk();
});

test('checkout rejects the placeholder stripe price before creating a customer', function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.stripe_price_id' => 'price_your_10_monthly_test_price',
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->post(route('billing.checkout'))
        ->assertSessionHas('error', 'Configure STRIPE_SUBSCRIPTION_PRICE_ID before starting checkout.');

    expect($workspace->refresh()->stripe_id)->toBeNull();
});

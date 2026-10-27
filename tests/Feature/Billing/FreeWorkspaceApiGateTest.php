<?php

use App\Enums\Platform;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Jobs\FetchAccountMessages;
use App\Jobs\FetchAccountReplies;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Messaging\MessageConnectorRegistry;
use App\Services\Metrics\MetricsConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'metrics.enabled' => true,
        'engagement.enabled' => true,
        'messages.enabled' => true,
    ]);

    // The first workspace is the exempt "initial" one; tests use later ones.
    Workspace::factory()->create();
});

function paidWorkspace(): Workspace
{
    $workspace = Workspace::factory()->create(['stripe_id' => 'cus_test_paid']);

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

function accountIn(Workspace $workspace, Platform $platform): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform->value,
        'capabilities' => ['dm_enabled' => true],
    ]);
}

/**
 * Fail the test if the job reaches a connector or refreshes a token.
 */
function expectNoPlatformCalls(): void
{
    foreach ([MetricsConnectorRegistry::class, EngagementConnectorRegistry::class, MessageConnectorRegistry::class] as $registry) {
        $mock = Mockery::mock($registry);
        $mock->shouldNotReceive('for');
        app()->instance($registry, $mock);
    }

    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    app()->instance(TokenManager::class, $tokens);
}

dataset('platform api jobs', [
    'account metrics' => [fn (ConnectedAccount $account) => new CaptureAccountMetrics($account)],
    'post metrics' => [fn (ConnectedAccount $account) => new CapturePostTargetMetrics(
        PostTarget::factory()->create(['connected_account_id' => $account->id, 'platform' => $account->platform, 'remote_id' => '1']),
    )],
    'post replies' => [fn (ConnectedAccount $account) => new FetchPostTargetReplies(
        PostTarget::factory()->create(['connected_account_id' => $account->id, 'platform' => $account->platform, 'remote_id' => '1']),
    )],
    'account replies' => [fn (ConnectedAccount $account) => new FetchAccountReplies($account)],
    'direct messages' => [fn (ConnectedAccount $account) => new FetchAccountMessages($account)],
]);

test('a free workspace makes no platform api call from background jobs', function (Closure $makeJob, Platform $platform) {
    $account = accountIn(Workspace::factory()->create(), $platform);
    expectNoPlatformCalls();

    dispatch_sync($makeJob($account));
})->with('platform api jobs')->with([Platform::X, Platform::Bluesky]);

test('a subscribed workspace with a spent X budget makes no X api call', function (Closure $makeJob) {
    $workspace = paidWorkspace();
    app(InstanceSettings::class)->setXWorkspaceBudget($workspace->id, 0);
    $account = accountIn($workspace, Platform::X);
    expectNoPlatformCalls();

    dispatch_sync($makeJob($account));
})->with('platform api jobs');

test('a subscribed workspace still captures metrics', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['followersCount' => 99, 'followsCount' => 5, 'postsCount' => 3])]);
    $account = ConnectedAccount::factory()->bluesky()->create(['workspace_id' => paidWorkspace()->id]);

    CaptureAccountMetrics::dispatchSync($account);

    expect($account->metrics()->count())->toBe(1);
});

test('platform api calls are allowed for every workspace when billing is off', function () {
    config(['subscriptions.enabled' => false]);
    $gate = app(WorkspaceSubscriptionGate::class);
    $workspace = Workspace::factory()->create();

    expect($gate->canCallPlatformApi($workspace, Platform::X))->toBeTrue()
        ->and($gate->canCallPlatformApi($workspace, Platform::Bluesky))->toBeTrue();
});

test('the initial workspace may always call platform apis', function () {
    $gate = app(WorkspaceSubscriptionGate::class);
    $initial = Workspace::query()->where('is_initial', true)->firstOrFail();

    expect($gate->canCallPlatformApi($initial, Platform::X))->toBeTrue()
        ->and($gate->canCallPlatformApi($initial, Platform::Bluesky))->toBeTrue();
});

<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Metrics\MetricsCaptureCadence;
use App\Services\Metrics\MetricsConnectorRegistry;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function publishedTikTokTarget(): PostTarget
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok,
        'status' => ConnectedAccountStatus::Active,
    ]);

    return PostTarget::factory()->for($account, 'account')->create([
        'platform' => Platform::TikTok,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'v_pub_1',
        'posted_at' => now()->subDay(),
    ]);
}

test('tiktok declares no metrics or engagement support', function () {
    expect(Platform::TikTok->supportsPostMetrics())->toBeFalse()
        ->and(Platform::TikTok->supportsAccountMetrics())->toBeFalse()
        ->and(Platform::TikTok->supportsEngagement())->toBeFalse()
        ->and(Platform::TikTok->supportsDirectMessages())->toBeFalse();
});

test('the metrics and engagement registries have no tiktok connector', function () {
    expect(fn () => app(MetricsConnectorRegistry::class)->for(Platform::TikTok))->toThrow(RuntimeException::class)
        ->and(fn () => app(EngagementConnectorRegistry::class)->for(Platform::TikTok))->toThrow(RuntimeException::class);
});

test('tiktok targets and accounts are never due for metrics capture', function () {
    $target = publishedTikTokTarget();
    $cadence = app(MetricsCaptureCadence::class);
    $now = Date::now()->toImmutable();

    expect($cadence->postTargetDue($target, $now))->toBeFalse()
        ->and($cadence->accountDue($target->account, $now))->toBeFalse();
});

test('a capture job that reaches a tiktok target records it as unsupported without calling TikTok', function () {
    Http::fake();
    $target = publishedTikTokTarget();

    CapturePostTargetMetrics::dispatchSync($target);
    CaptureAccountMetrics::dispatchSync($target->account);

    expect($target->refresh()->metrics_status)->toBe(MetricsStatus::Unsupported)
        ->and($target->account->refresh()->metrics_status)->toBe(MetricsStatus::Unsupported);
    Http::assertNothingSent();
});

test('the reply-fetch dispatcher never queues a tiktok target', function () {
    Queue::fake();
    config()->set('engagement.enabled', true);
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => collect(Platform::cases())
            ->mapWithKeys(fn (Platform $platform): array => [$platform->value => true])
            ->all(),
    ]);
    publishedTikTokTarget();

    test()->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNotPushed(FetchPostTargetReplies::class);
});

test('tiktok accounts are left out of follower analytics', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner]);
    Context::add('workspace_id', $workspace->id);
    $x = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X]);
    ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    test()->actingAs($user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $x->id));
});

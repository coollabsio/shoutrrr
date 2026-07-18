<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;

test('command dispatches jobs for due items and skips unsupported', function () {
    Queue::fake();

    // Shared account used by both PostTargets so it doesn't appear as an extra due account.
    $sharedAccount = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'status' => ConnectedAccountStatus::Active,
        'metrics_captured_at' => Date::now()->subMinutes(1), // recently captured → not due
    ]);

    PostTarget::factory()->create([
        'connected_account_id' => $sharedAccount->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'posted_at' => Date::now()->subHours(2),
        'metrics_captured_at' => null,
    ]);

    PostTarget::factory()->create([
        'connected_account_id' => $sharedAccount->id,
        'platform' => Platform::LinkedIn,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'urn:li:share:1',
        'posted_at' => Date::now()->subHours(2),
        'metrics_status' => MetricsStatus::Unsupported,
    ]);

    ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'status' => ConnectedAccountStatus::Active,
        'metrics_captured_at' => null,
    ]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertPushed(CapturePostTargetMetrics::class, 1);
    Queue::assertPushed(CaptureAccountMetrics::class, 1);
});

test('command is a no-op when feature disabled', function () {
    Queue::fake();
    config(['metrics.enabled' => false]);

    ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command is a no-op when the instance-settings override disables metrics, even though config is on', function () {
    Queue::fake();
    config(['metrics.enabled' => true]);
    app(InstanceSettings::class)->update(['metrics_enabled' => false]);

    ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command skips disabled metric polling groups', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'post_metrics_polling_enabled' => false,
        'account_metrics_polling_enabled' => false,
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'status' => ConnectedAccountStatus::Active,
        'metrics_captured_at' => null,
    ]);

    PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'posted_at' => Date::now()->subHours(2),
        'metrics_captured_at' => null,
    ]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command skips disabled connected accounts', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'status' => ConnectedAccountStatus::Active,
        'disabled_at' => Date::now(),
        'metrics_captured_at' => null,
    ]);

    PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/disabled',
        'posted_at' => Date::now()->subHours(2),
        'metrics_captured_at' => null,
    ]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command skips only disabled metric platforms', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'post_metrics_polling_enabled' => [
            'x' => false,
            'bluesky' => true,
            'linkedin' => true,
        ],
        'account_metrics_polling_enabled' => [
            'x' => false,
            'bluesky' => true,
            'linkedin' => true,
        ],
    ]);

    $xAccount = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'status' => ConnectedAccountStatus::Active,
        'metrics_captured_at' => null,
    ]);
    $blueskyAccount = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'status' => ConnectedAccountStatus::Active,
        'metrics_captured_at' => null,
    ]);

    PostTarget::factory()->create([
        'connected_account_id' => $xAccount->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'x-root',
        'posted_at' => Date::now()->subHours(2),
        'metrics_captured_at' => null,
    ]);
    PostTarget::factory()->create([
        'connected_account_id' => $blueskyAccount->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'posted_at' => Date::now()->subHours(2),
        'metrics_captured_at' => null,
    ]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertPushed(CapturePostTargetMetrics::class, 1);
    Queue::assertPushed(CaptureAccountMetrics::class, 1);
});

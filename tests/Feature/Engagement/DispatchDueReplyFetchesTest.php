<?php

// tests/Feature/Engagement/DispatchDueReplyFetchesTest.php
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Queue;

test('it dispatches a fetch job for a recently-published target', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

test('it skips targets published outside the window', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(30),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it skips targets checked inside the polling interval', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'engagement_poll_interval_minutes' => [
            'x' => 360,
            'bluesky' => 60,
            'linkedin' => 360,
        ],
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
        'reply_fetched_at' => now()->subMinutes(30),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it dispatches targets checked before the platform polling interval', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'engagement_poll_interval_minutes' => [
            'x' => 360,
            'bluesky' => 20,
            'linkedin' => 360,
        ],
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
        'platform' => Platform::Bluesky,
        'reply_fetched_at' => now()->subMinutes(21),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

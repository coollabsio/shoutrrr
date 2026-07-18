<?php

// tests/Feature/Engagement/DispatchDueReplyFetchesTest.php
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchAccountReplies;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Queue;

test('it collapses an X account\'s due posts into a single batched job', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->count(3)->for($account, 'account')->published()->create([
        'platform' => Platform::X,
        'posted_at' => now()->subHours(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchAccountReplies::class, 1);
    Queue::assertNotPushed(FetchPostTargetReplies::class);
});

test('command is a no-op when the instance-settings override disables engagement, even though config is on', function () {
    Queue::fake();
    config(['engagement.enabled' => true]);
    app(InstanceSettings::class)->update(['engagement_enabled' => false]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->published()->create([
        'platform' => Platform::X,
        'posted_at' => now()->subHours(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it skips accounts parked by a rate limit', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'status' => ConnectedAccountStatus::Active,
        'engagement_rate_limited_until' => now()->addMinutes(30),
    ]);
    PostTarget::factory()->for($account, 'account')->published()->create([
        'platform' => Platform::X,
        'posted_at' => now()->subHours(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it skips disabled connected accounts', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky,
        'status' => ConnectedAccountStatus::Active,
        'disabled_at' => now(),
    ]);
    PostTarget::factory()->for($account, 'account')->published()->create([
        'platform' => Platform::Bluesky,
        'posted_at' => now()->subHours(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it dispatches a fetch job for a recently-published target', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

test('it still dispatches an old never-fetched target at the steady cadence', function () {
    Queue::fake();

    // Old posts must NOT be abandoned: late replies still need to surface, just
    // on the coarse steady tail rather than the fast fresh-post cadence.
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(30),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

test('it skips an old target fetched within the steady interval', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(30),
        'reply_fetched_at' => now()->subMinutes(10), // < 1440m steady tail
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

test('it does not dispatch fetch jobs when engagement polling is disabled', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => false,
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it skips only disabled engagement platforms', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => [
            'x' => false,
            'bluesky' => true,
            'linkedin' => true,
        ],
    ]);

    $xAccount = ConnectedAccount::factory()->create(['platform' => Platform::X, 'status' => ConnectedAccountStatus::Active]);
    $blueskyAccount = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($xAccount, 'account')->create([
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'x-root',
        'posted_at' => now()->subDays(2),
    ]);
    PostTarget::factory()->for($blueskyAccount, 'account')->create([
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

test('it does not dispatch fetch jobs when all engagement platforms are disabled', function () {
    Queue::fake();
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => collect(Platform::cases())
            ->mapWithKeys(fn (Platform $platform): array => [$platform->value => false])
            ->all(),
    ]);

    foreach (Platform::cases() as $platform) {
        $account = ConnectedAccount::factory()->create([
            'platform' => $platform,
            'status' => ConnectedAccountStatus::Active,
        ]);

        PostTarget::factory()->for($account, 'account')->create([
            'platform' => $platform,
            'status' => PostTargetStatus::Published,
            'remote_id' => "{$platform->value}-root",
            'posted_at' => now()->subDays(2),
        ]);
    }

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it dispatches a target whose last fetch is older than its band interval', function () {
    Queue::fake();

    // 48h old -> 120m band. Last fetched 3h ago (> 120m) so it is due again.
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'platform' => Platform::Bluesky,
        'posted_at' => now()->subDays(2),
        'reply_fetched_at' => now()->subHours(3),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

test('a fresh in-band post with no prior fetch is dispatched', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'platform' => Platform::Bluesky,
        'posted_at' => now()->subHour(),
        'reply_fetched_at' => null,
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

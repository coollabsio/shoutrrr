<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Queue;

test('discord engagement polling is always disabled even if toggled on', function () {
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => collect(Platform::cases())
            ->mapWithKeys(fn (Platform $p): array => [$p->value => true])
            ->all(),
    ]);

    expect(app(InstanceSettings::class)->engagementPollingEnabled(Platform::Discord))->toBeFalse()
        ->and(app(InstanceSettings::class)->engagementPollingEnabled(Platform::X))->toBeTrue();
});

test('the reply-fetch dispatcher never queues a job for a Discord target', function () {
    Queue::fake();
    config()->set('engagement.enabled', true);
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => collect(Platform::cases())
            ->mapWithKeys(fn (Platform $p): array => [$p->value => true])
            ->all(),
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Discord,
        'status' => ConnectedAccountStatus::Active,
    ]);
    PostTarget::factory()->for($account, 'account')->create([
        'platform' => Platform::Discord,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'discord-msg',
        'posted_at' => now()->subDay(),
    ]);

    test()->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNotPushed(FetchPostTargetReplies::class);
});

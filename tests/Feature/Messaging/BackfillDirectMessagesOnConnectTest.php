<?php

use App\Enums\Platform;
use App\Events\ConnectedAccountConnected;
use App\Jobs\FetchAccountMessages;
use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['messages.enabled' => true]);
});

test('connecting a DM-capable account fetches its messages immediately', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
    ]);

    ConnectedAccountConnected::dispatch($account);

    Queue::assertPushed(FetchAccountMessages::class, fn ($job) => $job->account->is($account));
});

test('connecting an account without DM consent fetches nothing', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => [],
    ]);

    ConnectedAccountConnected::dispatch($account);

    Queue::assertNothingPushed();
});

test('connecting a platform with no DM API fetches nothing', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn,
        'capabilities' => ['dm_enabled' => true],
    ]);

    ConnectedAccountConnected::dispatch($account);

    Queue::assertNothingPushed();
});

test('connecting a disabled account fetches nothing', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'disabled_at' => now(),
    ]);

    ConnectedAccountConnected::dispatch($account);

    Queue::assertNothingPushed();
});

test('no backfill runs while messages are disabled instance-wide', function () {
    app(InstanceSettings::class)->update(['messages_enabled' => false]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
    ]);

    ConnectedAccountConnected::dispatch($account);

    Queue::assertNothingPushed();
});

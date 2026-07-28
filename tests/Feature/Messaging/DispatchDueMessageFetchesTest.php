<?php

declare(strict_types=1);

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Jobs\FetchAccountMessages;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => config()->set('messages.enabled', true));

test('dispatches jobs only for consented, non-rate-limited, enabled accounts', function () {
    Queue::fake();

    $ok = ConnectedAccount::factory()->create(['platform' => Platform::X, 'capabilities' => ['dm_enabled' => true]]);
    ConnectedAccount::factory()->create(['platform' => Platform::X, 'capabilities' => []]);                 // no consent
    ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn, 'capabilities' => ['dm_enabled' => true]]); // unsupported
    ConnectedAccount::factory()->create(['platform' => Platform::X, 'capabilities' => ['dm_enabled' => true], 'disabled_at' => now()]);

    $this->artisan('messages:dispatch-due')->assertOk();

    Queue::assertPushed(FetchAccountMessages::class, 1);
    Queue::assertPushed(FetchAccountMessages::class, fn ($job) => $job->account->is($ok));
});

test('skips accounts with a broken token that need reattention', function () {
    Queue::fake();

    ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'status' => ConnectedAccountStatus::NeedsAttention,
    ]);

    $this->artisan('messages:dispatch-due')->assertOk();

    Queue::assertNothingPushed();
});

test('does nothing when messaging disabled', function () {
    config()->set('messages.enabled', false);
    Queue::fake();
    ConnectedAccount::factory()->create(['platform' => Platform::X, 'capabilities' => ['dm_enabled' => true]]);
    $this->artisan('messages:dispatch-due')->assertOk();
    Queue::assertNothingPushed();
});

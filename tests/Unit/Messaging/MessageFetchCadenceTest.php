<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\MessageFetchCadence;

test('account never synced is due', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    expect(app(MessageFetchCadence::class)->isDue($account, now()->toImmutable()))->toBeTrue();
});

test('x account not due within 30 minute floor', function () {
    config()->set('messages.poll_interval_minutes.x', 30);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    Conversation::factory()->for($account, 'account')->create(['last_synced_at' => now()->subMinutes(10)]);
    expect(app(MessageFetchCadence::class)->isDue($account, now()->toImmutable()))->toBeFalse();
});

test('x account due after floor elapsed', function () {
    config()->set('messages.poll_interval_minutes.x', 30);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    Conversation::factory()->for($account, 'account')->create(['last_synced_at' => now()->subMinutes(45)]);
    expect(app(MessageFetchCadence::class)->isDue($account, now()->toImmutable()))->toBeTrue();
});

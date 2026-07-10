<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;

test('it does not refresh tokens for a frozen platform', function () {
    // An X account inside the refresh window (expiring soon, active).
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHours(1),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'refresh_token' => 'r',
    ]);

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    $this->mock(TokenManager::class, function ($mock): void {
        $mock->shouldNotReceive('fresh');
    });

    $this->artisan('accounts:refresh-tokens')->assertSuccessful();
});

test('it still refreshes tokens for an available platform', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHours(1),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'refresh_token' => 'r',
    ]);

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => true]]);

    $this->mock(TokenManager::class, function ($mock) use ($account): void {
        $mock->shouldReceive('fresh')
            ->once()
            ->withArgs(fn (ConnectedAccount $refreshed): bool => $refreshed->is($account));
    });

    $this->artisan('accounts:refresh-tokens')->assertSuccessful();
});

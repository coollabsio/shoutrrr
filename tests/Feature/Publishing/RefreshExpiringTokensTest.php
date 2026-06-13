<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use Illuminate\Support\Facades\Http;

test('it refreshes accounts nearing expiry', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addMinutes(5),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'refresh_token' => 'r',
    ]);

    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 7200]),
    ]);

    $this->artisan('accounts:refresh-tokens')->assertExitCode(0);

    expect($account->fresh()->secret->access_token)->toBe('fresh');
});

test('it flips status to needs attention on refresh failure and keeps going', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addMinutes(5),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'refresh_token' => 'r']);

    Http::fake(['https://api.twitter.com/2/oauth2/token' => Http::response([], 400)]);

    $this->artisan('accounts:refresh-tokens')->assertExitCode(0);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention);
});

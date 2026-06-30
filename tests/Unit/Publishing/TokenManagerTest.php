<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Atproto\DPoP;
use App\Services\Publishing\TokenManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('refreshes bluesky oauth tokens with dpop and returns a bluesky session payload', function () {
    $key = app(DPoP::class)->generateKey();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky->value,
        'auth_method' => 'oauth',
        'token_expires_at' => now()->subMinute(),
        'status' => ConnectedAccountStatus::NeedsAttention->value,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'session' => [
            'pds' => 'https://pds.example',
            'token_endpoint' => 'https://auth.example/oauth/token',
            'client_id' => 'https://app.example/oauth/bluesky/client-metadata.json',
            'dpop_private_jwk' => $key,
            'dpop_nonce' => 'old-nonce',
        ],
    ]);

    Http::fake([
        'https://auth.example/oauth/token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ], 200, ['DPoP-Nonce' => 'new-nonce']),
    ]);

    $credentials = app(TokenManager::class)->fresh($account);

    expect($credentials['session']['accessJwt'])->toBe('new-access')
        ->and($credentials['session']['dpop_nonce'])->toBe('new-nonce')
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->secret->refresh()->refresh_token)->toBe('new-refresh');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('DPoP')
        && $request['grant_type'] === 'refresh_token'
        && $request['client_id'] === 'https://app.example/oauth/bluesky/client-metadata.json');
});

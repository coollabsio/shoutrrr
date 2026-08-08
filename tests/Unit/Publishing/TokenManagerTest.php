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

it('sends the private_key_jwt assertion on refresh even when route() drifts from the stored client_id', function () {
    // Regression: refreshOAuth runs in queue workers, where route('oauth.bluesky.metadata')
    // derives its scheme/host from APP_URL and drifts from the web-request context that
    // connected the account (e.g. behind a reverse proxy). The old
    // `usesAssertion = clientId === route(...)` check then went false, the confidential
    // client dropped its private_key_jwt assertion, and Bluesky rejected the refresh with
    // invalid_client, flipping the account to needs-attention. atproto requires refresh to
    // authenticate with the same method used at connect.
    $key = app(DPoP::class)->generateKey();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky->value,
        'auth_method' => 'oauth',
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'session' => [
            'pds' => 'https://pds.example',
            'token_endpoint' => 'https://auth.example/oauth/token',
            'issuer' => 'https://auth.example',
            // Captured at connect behind a proxy (https + real host); differs from the
            // worker's route('oauth.bluesky.metadata') (http://localhost in tests).
            'client_id' => 'https://app.example/oauth/bluesky/client-metadata.json',
            'dpop_private_jwk' => $key,
            'dpop_nonce' => 'old-nonce',
        ],
    ]);

    expect('https://app.example/oauth/bluesky/client-metadata.json')
        ->not->toBe(route('oauth.bluesky.metadata'));

    Http::fake([
        'https://auth.example/oauth/token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ], 200, ['DPoP-Nonce' => 'new-nonce']),
    ]);

    app(TokenManager::class)->fresh($account->fresh());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example/oauth/token'
        && $request['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        && isset($request['client_assertion'])
        && $request['client_id'] === 'https://app.example/oauth/bluesky/client-metadata.json');

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::Active);
});

it('uses a bluesky oauth token rotated by another worker instead of refreshing again', function () {
    $key = app(DPoP::class)->generateKey();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Bluesky->value,
        'auth_method' => 'oauth',
        'token_expires_at' => now()->subMinute(),
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

    // Snapshot the stale account, then have a concurrent worker rotate the
    // single-use refresh token and mint a fresh access token before we run.
    $staleAccount = $account->fresh();

    $account->forceFill([
        'token_expires_at' => now()->addHour(),
        'last_refreshed_at' => now(),
    ])->save();
    $account->secret->forceFill([
        'access_token' => 'fresh-from-worker',
        'refresh_token' => 'rotated-by-worker',
    ])->save();

    Http::fake([
        'https://auth.example/oauth/token' => Http::response([
            'access_token' => 'unnecessary-refresh',
            'refresh_token' => 'unnecessary-rotation',
            'expires_in' => 3600,
        ]),
    ]);

    $credentials = app(TokenManager::class)->fresh($staleAccount);

    // Re-reading under the lock surfaces the worker's token; the rotated
    // refresh token is never re-sent, so no invalid_grant race occurs.
    expect($credentials['access_token'])->toBe('fresh-from-worker')
        ->and($credentials['session']['accessJwt'])->toBe('fresh-from-worker')
        ->and($account->secret->refresh()->refresh_token)->toBe('rotated-by-worker');

    Http::assertNothingSent();
});

<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Atproto\DPoP;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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
    // Captured at connect behind a proxy (https + real host); must differ from the
    // worker's route('oauth.bluesky.metadata') to exercise the drift. Guarantee the
    // mismatch rather than depending on the test env's APP_URL.
    $storedClientId = 'https://app.example/oauth/bluesky/client-metadata.json';
    if ($storedClientId === route('oauth.bluesky.metadata')) {
        $storedClientId .= '?connected_behind_proxy=1';
    }

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
            'client_id' => $storedClientId,
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

    app(TokenManager::class)->fresh($account->fresh());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example/oauth/token'
        && $request['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        && isset($request['client_assertion'])
        && $request['client_id'] === $storedClientId);

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

it('refreshes a bluesky app-password session under the lock and returns the rotated session', function () {
    $account = ConnectedAccount::factory()->bluesky()->create();
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'app-pass',
        'session' => [
            'pds' => 'https://pds.example',
            'accessJwt' => 'stale-access',
            'refreshJwt' => 'stale-refresh',
        ],
    ]);

    Http::fake([
        'https://pds.example/xrpc/com.atproto.server.refreshSession' => Http::response([
            'accessJwt' => 'new-access',
            'refreshJwt' => 'new-refresh',
        ]),
    ]);

    $credentials = app(TokenManager::class)->fresh($account);

    expect($credentials['session']['accessJwt'])->toBe('new-access')
        ->and($credentials['session']['refreshJwt'])->toBe('new-refresh')
        ->and($credentials['app_password'])->toBe('app-pass')
        ->and($account->secret->refresh()->session['accessJwt'])->toBe('new-access')
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::Active);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer stale-refresh')
        && str_ends_with($request->url(), '/xrpc/com.atproto.server.refreshSession'));
});

it('retries the bluesky refresh once to complete the dpop nonce handshake', function () {
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
            'token_endpoint' => 'https://auth.example/oauth/token',
            'client_id' => 'https://app.example/oauth/bluesky/client-metadata.json',
            'dpop_private_jwk' => $key,
        ],
    ]);

    // atproto answers the first token request with 400 use_dpop_nonce and a server
    // nonce, WITHOUT consuming the refresh token, then accepts the re-POST.
    Http::fake([
        'https://auth.example/oauth/token' => Http::sequence()
            ->push(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'server-nonce'])
            ->push(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600], 200, ['DPoP-Nonce' => 'final-nonce']),
    ]);

    $credentials = app(TokenManager::class)->fresh($account);

    expect($credentials['session']['accessJwt'])->toBe('new-access')
        ->and($account->secret->refresh()->refresh_token)->toBe('new-refresh')
        ->and($account->fresh()->status)->toBe(ConnectedAccountStatus::Active);

    Http::assertSentCount(2);
});

it('does not re-send a bluesky refresh token when the failure is not a nonce challenge', function () {
    // Regression for "Refresh token replayed": retrying on any failure could re-POST a
    // single-use token the server already rotated. Only the use_dpop_nonce handshake is
    // safe to retry; a genuine rejection must flip the account and stop, not re-send.
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
            'token_endpoint' => 'https://auth.example/oauth/token',
            'client_id' => 'https://app.example/oauth/bluesky/client-metadata.json',
            'dpop_private_jwk' => $key,
            'dpop_nonce' => 'old-nonce',
        ],
    ]);

    // A real rejection that still carries a DPoP-Nonce header — the old code would have
    // re-POSTed the token on the strength of that header alone.
    Http::fake([
        'https://auth.example/oauth/token' => Http::response(
            ['error' => 'invalid_grant', 'error_description' => 'Refresh token replayed'],
            400,
            ['DPoP-Nonce' => 'server-nonce'],
        ),
    ]);

    expect(fn () => app(TokenManager::class)->fresh($account))->toThrow(TokenRefreshException::class);

    Http::assertSentCount(1);
    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention)
        ->and($account->fresh()->refresh_failure_reason)->toContain('Refresh token replayed');
});

it('falls back to the persisted bluesky session when the refresh lock times out', function () {
    $account = ConnectedAccount::factory()->bluesky()->create();
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'app-pass',
        'session' => [
            'pds' => 'https://pds.example',
            'accessJwt' => 'persisted-access',
            'refreshJwt' => 'persisted-refresh',
        ],
    ]);

    // Simulate another worker holding the per-account lock: block() times out. The
    // path must degrade to the persisted session rather than throw (which would fail
    // the tries=1 publish job and risk flipping the account to needs-attention).
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    Http::fake();

    $credentials = app(TokenManager::class)->fresh($account);

    expect($credentials['session']['accessJwt'])->toBe('persisted-access')
        ->and($credentials['session']['refreshJwt'])->toBe('persisted-refresh')
        ->and($credentials['app_password'])->toBe('app-pass');

    Http::assertNothingSent();
});

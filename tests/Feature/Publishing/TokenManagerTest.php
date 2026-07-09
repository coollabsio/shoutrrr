<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;

test('fresh returns existing credentials when token is not near expiry', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'still-good',
    ]);

    Http::fake();

    $creds = app(TokenManager::class)->fresh($account->fresh());

    expect($creds['access_token'])->toBe('still-good');
    Http::assertNothingSent();
});

test('fresh refreshes an expired oauth token and persists it', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old',
        'refresh_token' => 'refresh-old',
    ]);

    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 7200,
        ]),
    ]);

    $creds = app(TokenManager::class)->fresh($account->fresh());

    expect($creds['access_token'])->toBe('new-access');

    $account->refresh();
    expect($account->secret->access_token)->toBe('new-access')
        ->and($account->secret->refresh_token)->toBe('new-refresh')
        ->and($account->last_refreshed_at)->not->toBeNull();
});

test('fresh uses a token refreshed by another worker instead of refreshing again', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old',
        'refresh_token' => 'refresh-old',
    ]);

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
        'https://api.twitter.com/2/oauth2/token' => Http::response([
            'access_token' => 'unnecessary-refresh',
            'refresh_token' => 'unnecessary-rotation',
            'expires_in' => 7200,
        ]),
    ]);

    $creds = app(TokenManager::class)->fresh($staleAccount);

    expect($creds['access_token'])->toBe('fresh-from-worker')
        ->and($account->fresh()->secret->refresh_token)->toBe('rotated-by-worker');

    Http::assertNothingSent();
});

test('fresh flips status and throws on refresh failure', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'refresh_token' => 'bad',
    ]);

    Http::fake(['https://api.twitter.com/2/oauth2/token' => Http::response([], 400)]);

    expect(fn () => app(TokenManager::class)->fresh($account->fresh()))
        ->toThrow(TokenRefreshException::class);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention);
});

test('x token refresh authenticates with http basic auth (confidential client)', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'csecret');

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old',
        'refresh_token' => 'refresh-old',
    ]);

    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 7200,
        ]),
    ]);

    app(TokenManager::class)->fresh($account->fresh());

    // X is a confidential client: credentials go in the Authorization header
    // (Basic), with client_id also in the body, and NO client_secret in the body.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/oauth2/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('cid:csecret'))
        && $request['client_id'] === 'cid'
        && ! isset($request['client_secret']));
});

test('linkedin token refresh sends credentials in the body', function () {
    config()->set('services.linkedin-openid.client_id', 'lid');
    config()->set('services.linkedin-openid.client_secret', 'lsecret');

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'refresh_token' => 'refresh-old',
    ]);

    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 3600,
        ]),
    ]);

    app(TokenManager::class)->fresh($account->fresh());

    Http::assertSent(fn ($request) => $request['client_id'] === 'lid'
        && $request['client_secret'] === 'lsecret'
        && ! $request->hasHeader('Authorization'));
});

test('fresh refreshes the bluesky session before publishing and persists the new tokens', function () {
    $account = ConnectedAccount::factory()->bluesky()->create(['token_expires_at' => null]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'app-pass',
        'session' => ['accessJwt' => 'stale-jwt', 'refreshJwt' => 'rjwt', 'pds' => 'https://bsky.social'],
    ]);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
            'accessJwt' => 'fresh-jwt',
            'refreshJwt' => 'fresh-rjwt',
        ]),
    ]);

    $creds = app(TokenManager::class)->fresh($account->fresh());

    // The stale accessJwt is replaced with the refreshed one, never reused.
    expect($creds['session']['accessJwt'])->toBe('fresh-jwt')
        ->and($creds['app_password'])->toBe('app-pass');

    // refreshSession authenticates with the refreshJwt as the bearer token.
    Http::assertSent(fn ($request) => $request->url() === 'https://bsky.social/xrpc/com.atproto.server.refreshSession'
        && $request->hasHeader('Authorization', 'Bearer rjwt'));

    // The new pair is persisted so the next publish starts from a valid token.
    $account->refresh();
    expect($account->secret->session['accessJwt'])->toBe('fresh-jwt')
        ->and($account->secret->session['refreshJwt'])->toBe('fresh-rjwt')
        ->and($account->last_refreshed_at)->not->toBeNull();
});

test('fresh falls back to an app-password login when the refresh token has lapsed', function () {
    $account = ConnectedAccount::factory()->bluesky()->create([
        'token_expires_at' => null,
        'remote_account_id' => 'did:plc:abc123',
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'app-pass',
        'session' => ['accessJwt' => 'stale-jwt', 'refreshJwt' => 'expired-rjwt', 'pds' => 'https://bsky.social'],
    ]);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'ExpiredToken'], 400),
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'accessJwt' => 'login-jwt',
            'refreshJwt' => 'login-rjwt',
        ]),
    ]);

    $creds = app(TokenManager::class)->fresh($account->fresh());

    expect($creds['session']['accessJwt'])->toBe('login-jwt');

    // The login uses the DID as identifier and the stored app password.
    Http::assertSent(fn ($request) => $request->url() === 'https://bsky.social/xrpc/com.atproto.server.createSession'
        && $request['identifier'] === 'did:plc:abc123'
        && $request['password'] === 'app-pass');
});

test('fresh refreshes a threads token via refresh_access_token and persists the new expiry', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old-long-token',
    ]);

    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([
            'access_token' => 'new-long-token',
            'expires_in' => 5183944,
        ]),
    ]);

    $creds = app(TokenManager::class)->fresh($account->fresh());

    expect($creds['access_token'])->toBe('new-long-token');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/refresh_access_token?grant_type=th_refresh_token&access_token=old-long-token');

    $account->refresh();
    expect($account->secret->access_token)->toBe('new-long-token')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->last_refreshed_at)->not->toBeNull()
        ->and($account->token_expires_at->diffInDays(now(), true))->toBeGreaterThan(59);
});

test('fresh does not refresh a threads token that is not near expiry', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'token_expires_at' => now()->addDays(30),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'still-good-long-token',
    ]);

    Http::fake();

    $creds = app(TokenManager::class)->fresh($account->fresh());

    expect($creds['access_token'])->toBe('still-good-long-token');
    Http::assertNothingSent();
});

test('fresh flips a threads account to needs-attention and throws on refresh failure', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'token_expires_at' => now()->subMinute(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'stale-long-token',
    ]);

    Http::fake(['https://graph.threads.net/refresh_access_token*' => Http::response([], 400)]);

    expect(fn () => app(TokenManager::class)->fresh($account->fresh()))
        ->toThrow(TokenRefreshException::class);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention);
});

test('fresh flags the bluesky account for attention when both refresh and login fail', function () {
    $account = ConnectedAccount::factory()->bluesky()->create([
        'token_expires_at' => null,
        'remote_account_id' => 'did:plc:abc123',
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'revoked-pass',
        'session' => ['accessJwt' => 'stale-jwt', 'refreshJwt' => 'expired-rjwt', 'pds' => 'https://bsky.social'],
    ]);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([], 400),
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired'], 401),
    ]);

    app(TokenManager::class)->fresh($account->fresh());

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention);
});

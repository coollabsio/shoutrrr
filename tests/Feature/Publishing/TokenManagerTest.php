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

test('fresh returns bluesky session credentials', function () {
    $account = ConnectedAccount::factory()->bluesky()->create(['token_expires_at' => null]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'app-pass',
        'session' => ['accessJwt' => 'jwt', 'refreshJwt' => 'rjwt', 'pds' => 'https://bsky.social'],
    ]);

    Http::fake();

    $creds = app(TokenManager::class)->fresh($account->fresh());

    expect($creds['session']['accessJwt'])->toBe('jwt')
        ->and($creds['app_password'])->toBe('app-pass');
});

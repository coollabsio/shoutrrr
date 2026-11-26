<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Workspace;
use App\Services\Auth\Socialite\TikTokProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

const TIKTOK_OAUTH_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
const TIKTOK_OAUTH_REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';
const TIKTOK_USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/*';

beforeEach(function () {
    config()->set('services.tiktok.client_id', 'tiktok-client-key');
    config()->set('services.tiktok.client_secret', 'tiktok-client-secret');
    config()->set('services.tiktok.redirect', 'https://app.test/accounts/callback/tiktok');
});

/**
 * @param  array<string, mixed>  $token
 */
function fakeTikTokOAuth(array $token = []): void
{
    Http::fake([
        TIKTOK_OAUTH_TOKEN_URL => Http::response([
            'access_token' => 'tiktok-access',
            'refresh_token' => 'tiktok-refresh',
            'expires_in' => 7200,
            'refresh_expires_in' => 15552000,
            'open_id' => 'open-123',
            'scope' => 'user.info.basic,user.info.profile,video.publish,video.upload',
            'token_type' => 'Bearer',
            ...$token,
        ]),
        TIKTOK_USER_INFO_URL => Http::response([
            'data' => ['user' => [
                'open_id' => 'open-123',
                'username' => 'harbor.studio',
                'display_name' => 'Harbor Studio',
                'avatar_url' => 'https://p16.tiktokcdn.test/avatar.jpeg',
            ]],
            'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-1'],
        ]),
        TIKTOK_OAUTH_REVOKE_URL => Http::response([]),
    ]);
}

/**
 * Start the connect flow and return the state Socialite stored in the session.
 */
function startTikTokConnect(): string
{
    $location = test()->get('/accounts/connect/tiktok')->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    // Socialite caches each driver with the request it was built for; drop it so
    // the callback request gets a provider reading that request, as in production.
    Socialite::forgetDrivers();

    return (string) $query['state'];
}

test('the tiktok driver resolves to the TikTok provider', function () {
    expect(Socialite::driver('tiktok'))->toBeInstanceOf(TikTokProvider::class);
});

test('connect redirects to TikTok with the client key and comma-separated scopes', function () {
    ownerActingIn();

    $location = test()->get('/accounts/connect/tiktok')->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.tiktok.com/v2/auth/authorize/?')
        ->and($query['client_key'])->toBe('tiktok-client-key')
        ->and($query)->not->toHaveKey('client_id')
        ->and($query['scope'])->toBe('user.info.basic,user.info.profile,video.publish,video.upload')
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe(route('accounts.callback', ['platform' => 'tiktok']))
        ->and($query['state'])->toBe(session('state'));
});

test('connect 404s while TikTok is not configured', function () {
    config()->set('services.tiktok.client_secret', null);
    ownerActingIn();

    test()->get('/accounts/connect/tiktok')->assertNotFound();
});

test('the callback stores an active tiktok account with its tokens encrypted', function () {
    [, $workspace] = ownerActingIn();
    fakeTikTokOAuth();
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', 'TikTok account connected.');

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'open-123');

    expect($account)->not->toBeNull()
        ->and($account->platform)->toBe(Platform::TikTok)
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->workspace_id)->toBe($workspace->id)
        ->and($account->handle)->toBe('@harbor.studio')
        ->and($account->display_name)->toBe('Harbor Studio')
        ->and($account->secret->access_token)->toBe('tiktok-access')
        ->and($account->secret->refresh_token)->toBe('tiktok-refresh')
        ->and($account->token_expires_at->between(now()->addSeconds(7100), now()->addSeconds(7201)))->toBeTrue();

    $stored = DB::table('connected_account_secrets')->where('connected_account_id', $account->id)->first();
    expect($stored->access_token)->not->toContain('tiktok-access')
        ->and($stored->refresh_token)->not->toContain('tiktok-refresh');

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_OAUTH_TOKEN_URL
        && $request['client_key'] === 'tiktok-client-key'
        && $request['client_secret'] === 'tiktok-client-secret'
        && $request['code'] === 'auth-code'
        && $request['grant_type'] === 'authorization_code'
        && ! isset($request['client_id']));
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://open.tiktokapis.com/v2/user/info/')
        && $request->hasHeader('Authorization', 'Bearer tiktok-access')
        && str_contains($request->url(), 'fields=open_id%2Cavatar_url%2Cdisplay_name%2Cusername'));
});

test('the callback refuses a state that does not match the session', function () {
    ownerActingIn();
    fakeTikTokOAuth();
    startTikTokConnect();

    test()->get('/accounts/callback/tiktok?code=auth-code&state=forged')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', "We couldn't connect your TikTok account. Please try again.");

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

test('the callback state can only be used once', function () {
    ownerActingIn();
    fakeTikTokOAuth();
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")->assertRedirect(route('accounts.index'));
    Socialite::forgetDrivers();
    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")->assertRedirect(route('accounts.index'));

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(1);
    Http::assertSentCount(2); // one token exchange and one profile read, never repeated
});

test('the callback reports a declined consent without calling TikTok', function () {
    ownerActingIn();
    Http::fake();

    test()->get('/accounts/callback/tiktok?error=access_denied&error_description=User+cancelled')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', 'You declined to connect your TikTok account.');

    Http::assertNothingSent();
});

test('the callback fails cleanly when TikTok rejects the code in the response body', function () {
    ownerActingIn();
    Http::fake([
        TIKTOK_OAUTH_TOKEN_URL => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'Authorization code is expired.',
            'log_id' => 'log-2',
        ]),
    ]);
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=stale&state={$state}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('the callback refuses a partial grant and revokes it', function () {
    ownerActingIn();
    fakeTikTokOAuth(['scope' => 'user.info.basic,video.upload']);
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', 'TikTok was connected without every permission Shoutrrr needs (user.info.profile, video.publish). Connect again and leave all permissions switched on.');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_OAUTH_REVOKE_URL
        && $request['token'] === 'tiktok-access'
        && $request['client_key'] === 'tiktok-client-key');
});

test('the callback revokes the token when TikTok withholds the profile', function () {
    ownerActingIn();
    Http::fake([
        TIKTOK_OAUTH_TOKEN_URL => Http::response([
            'access_token' => 'tiktok-access',
            'refresh_token' => 'tiktok-refresh',
            'expires_in' => 7200,
            'refresh_expires_in' => 15552000,
            'open_id' => 'open-123',
            'scope' => 'user.info.basic,video.publish,video.upload',
        ]),
        TIKTOK_USER_INFO_URL => Http::response([
            'data' => ['user' => ['open_id' => 'open-123', 'display_name' => 'Harbor Studio']],
            'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-2'],
        ]),
        TIKTOK_OAUTH_REVOKE_URL => Http::response([]),
    ]);
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_OAUTH_REVOKE_URL
        && $request['token'] === 'tiktok-access');
});

test('a refused tiktok connection keeps the grant another workspace still uses', function () {
    ownerActingIn();
    ConnectedAccount::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'platform' => Platform::TikTok->value,
        'remote_account_id' => 'open-123',
    ]);
    fakeTikTokOAuth(['scope' => 'user.info.basic,video.upload']);
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")
        ->assertSessionHas('error');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === TIKTOK_OAUTH_REVOKE_URL);
});

test('the callback fails when the profile read returns an api error', function () {
    ownerActingIn();
    Http::fake([
        TIKTOK_OAUTH_TOKEN_URL => Http::response([
            'access_token' => 'tiktok-access',
            'refresh_token' => 'tiktok-refresh',
            'expires_in' => 7200,
            'refresh_expires_in' => 15552000,
            'open_id' => 'open-123',
            'scope' => 'user.info.basic,user.info.profile,video.publish,video.upload',
        ]),
        TIKTOK_USER_INFO_URL => Http::response([
            'data' => [],
            'error' => ['code' => 'scope_not_authorized', 'message' => 'missing scope', 'log_id' => 'log-3'],
        ], 401),
    ]);
    $state = startTikTokConnect();

    test()->get("/accounts/callback/tiktok?code=auth-code&state={$state}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

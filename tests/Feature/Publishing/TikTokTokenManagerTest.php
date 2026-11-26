<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Exceptions\TransientTokenRefreshException;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountNeedsAttentionNotification;
use App\Notifications\AccountReconnectDueNotification;
use App\Services\Publishing\TokenManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

const TIKTOK_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

beforeEach(function () {
    config()->set('services.tiktok.client_id', 'tiktok-client-key');
    config()->set('services.tiktok.client_secret', 'tiktok-client-secret');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function tikTokAccount(array $attributes = []): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok->value,
        'token_expires_at' => now()->subMinute(),
        ...$attributes,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
    ]);

    return $account->fresh();
}

test('fresh returns the stored tiktok token while it is not near expiry', function () {
    $account = tikTokAccount(['token_expires_at' => now()->addHour()]);
    Http::fake();

    expect(app(TokenManager::class)->fresh($account)['access_token'])->toBe('old-access');

    Http::assertNothingSent();
});

test('fresh refreshes an expired tiktok token with the client key and persists the rotated pair', function () {
    $account = tikTokAccount();
    Http::fake([
        TIKTOK_TOKEN_URL => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 7200,
            'refresh_expires_in' => 15552000,
            'open_id' => 'open-1',
            'scope' => 'user.info.basic,video.publish',
            'token_type' => 'Bearer',
        ]),
    ]);

    $credentials = app(TokenManager::class)->fresh($account);

    expect($credentials)->toBe(['access_token' => 'new-access']);

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_TOKEN_URL
        && $request->isForm()
        && $request['client_key'] === 'tiktok-client-key'
        && $request['client_secret'] === 'tiktok-client-secret'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh'
        && ! isset($request['client_id']));

    $account->refresh();
    expect($account->secret->access_token)->toBe('new-access')
        ->and($account->secret->refresh_token)->toBe('new-refresh')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->last_refreshed_at)->not->toBeNull()
        ->and($account->token_expires_at->between(now()->addSeconds(7100), now()->addSeconds(7201)))->toBeTrue()
        ->and($account->refresh_token_expires_at->between(now()->addSeconds(15551900), now()->addSeconds(15552001)))->toBeTrue();
});

test('fresh uses a tiktok token another worker already rotated', function () {
    $account = tikTokAccount();
    $stale = $account->fresh();

    $account->forceFill(['token_expires_at' => now()->addHours(2), 'last_refreshed_at' => now()])->save();
    $account->secret->forceFill(['access_token' => 'from-worker', 'refresh_token' => 'rotated-by-worker'])->save();

    Http::fake();

    expect(app(TokenManager::class)->fresh($stale)['access_token'])->toBe('from-worker')
        ->and($account->fresh()->secret->refresh_token)->toBe('rotated-by-worker');

    Http::assertNothingSent();
});

test('a forced tiktok refresh after a rejected token reuses a token already replaced', function () {
    $account = tikTokAccount(['token_expires_at' => now()->addHour()]);
    $account->secret->forceFill(['access_token' => 'replaced-meanwhile'])->save();

    Http::fake();

    $credentials = app(TokenManager::class)->fresh($account->fresh(), force: true, rejectedAccessToken: 'old-access');

    expect($credentials['access_token'])->toBe('replaced-meanwhile');
    Http::assertNothingSent();
});

test('a forced tiktok refresh rotates the token TikTok rejected', function () {
    $account = tikTokAccount(['token_expires_at' => now()->addHour()]);
    Http::fake([
        TIKTOK_TOKEN_URL => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 7200, 'refresh_expires_in' => 15552000]),
    ]);

    $credentials = app(TokenManager::class)->fresh($account, force: true, rejectedAccessToken: 'old-access');

    expect($credentials['access_token'])->toBe('new-access');
    Http::assertSentCount(1);
});

test('fresh flags the tiktok account when the refresh token is rejected', function () {
    $account = tikTokAccount();
    // TikTok reports OAuth errors in the body, often with HTTP 200.
    Http::fake([
        TIKTOK_TOKEN_URL => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token is invalid or expired.',
            'log_id' => 'log-1',
        ]),
    ]);

    expect(fn () => app(TokenManager::class)->fresh($account))
        ->toThrow(TokenRefreshException::class, 'invalid_grant');

    $account->refresh();
    expect($account->status)->toBe(ConnectedAccountStatus::NeedsAttention)
        ->and($account->refresh_failure_reason)->toContain('invalid_grant')
        ->and($account->refresh_failure_reason)->not->toContain('old-refresh')
        ->and($account->secret->refresh_token)->toBe('old-refresh');
});

test('fresh keeps the tiktok account active on a transient refresh failure', function (int $status, array $body) {
    $account = tikTokAccount();
    Http::fake([TIKTOK_TOKEN_URL => Http::response($body, $status)]);

    expect(fn () => app(TokenManager::class)->fresh($account))
        ->toThrow(TransientTokenRefreshException::class);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->fresh()->refresh_failed_at)->toBeNull()
        ->and($account->fresh()->secret->refresh_token)->toBe('old-refresh');
})->with([
    'rate limited' => [429, []],
    'server error' => [503, []],
    'temporarily unavailable in the body' => [200, ['error' => 'temporarily_unavailable']],
    'server_error in the body' => [200, ['error' => 'server_error']],
]);

test('fresh keeps the tiktok account active when TikTok cannot be reached', function () {
    $account = tikTokAccount();
    Http::fake([TIKTOK_TOKEN_URL => fn () => throw new ConnectionException('Timed out')]);

    expect(fn () => app(TokenManager::class)->fresh($account))
        ->toThrow(TransientTokenRefreshException::class);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::Active);
});

test('fresh treats a tiktok refresh response without a rotated refresh token as a failure', function () {
    $account = tikTokAccount();
    Http::fake([TIKTOK_TOKEN_URL => Http::response(['access_token' => 'new-access'])]);

    expect(fn () => app(TokenManager::class)->fresh($account))
        ->toThrow(TokenRefreshException::class);

    expect($account->fresh()->secret->access_token)->toBe('old-access');
});

test('the proactive sweep leaves tiktok accounts alone while their refresh token has time left', function (array $attributes) {
    $account = tikTokAccount($attributes);
    Http::fake();
    Notification::fake();

    test()->artisan('accounts:refresh-tokens')->assertExitCode(0);

    Http::assertNothingSent();
    Notification::assertNothingSent();
    expect($account->fresh()->secret->refresh_token)->toBe('old-refresh');
})->with([
    'refresh token valid for months, account idle for months' => [['refresh_token_expires_at' => now()->addDays(60), 'last_refreshed_at' => now()->subDays(200)]],
    'expiry unknown, refreshed today' => [['last_refreshed_at' => now()->subHours(3)]],
    'expiry unknown, connected today' => [['last_refreshed_at' => null]],
]);

test('the proactive sweep refreshes a tiktok account ahead of its refresh token running out', function (array $attributes) {
    $account = tikTokAccount($attributes);
    Http::fake([TIKTOK_TOKEN_URL => Http::response([
        'access_token' => 'new-access',
        'refresh_token' => 'new-refresh',
        'expires_in' => 86400,
        'refresh_expires_in' => 31536000,
    ])]);
    Notification::fake();

    test()->artisan('accounts:refresh-tokens')->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
    $account->refresh();
    expect($account->secret->refresh_token)->toBe('new-refresh')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->last_refreshed_at->isToday())->toBeTrue()
        ->and($account->refresh_token_expires_at->gt(now()->addDays(364)))->toBeTrue()
        ->and($account->reconnect_reminded_at)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'refresh token runs out in ten days' => [['refresh_token_expires_at' => now()->addDays(10), 'last_refreshed_at' => now()->subDays(3)]],
    'expiry unknown, last refreshed two days ago' => [['last_refreshed_at' => now()->subDays(2)]],
    'expiry unknown, never refreshed, connected two days ago' => [['last_refreshed_at' => null, 'created_at' => now()->subDays(2)]],
]);

test('the proactive sweep reminds the workspace once to reconnect a tiktok account TikTok will not renew', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $account = tikTokAccount([
        'workspace_id' => Workspace::factory()->create(['owner_id' => $owner->id])->id,
        'connected_by_user_id' => $owner->id,
        'handle' => '@harbor.studio',
        'refresh_token_expires_at' => now()->addDays(10),
        'last_refreshed_at' => now()->subDays(3),
    ]);
    // What the sandbox does: the same refresh token comes back, still counting
    // down to the date it was granted with.
    Http::fake([TIKTOK_TOKEN_URL => Http::response([
        'access_token' => 'new-access',
        'refresh_token' => 'old-refresh',
        'expires_in' => 86400,
        'refresh_expires_in' => 10 * 86400,
    ])]);
    Notification::fake();

    test()->artisan('accounts:refresh-tokens')->assertExitCode(0);
    test()->artisan('accounts:refresh-tokens')->assertExitCode(0);

    Http::assertSentCount(1);
    Notification::assertSentTimes(AccountReconnectDueNotification::class, 1);
    Notification::assertSentTo($owner, AccountReconnectDueNotification::class, function (AccountReconnectDueNotification $notification) use ($owner): bool {
        $payload = $notification->toArray($owner);

        return $payload['title'] === 'TikTok account needs reconnecting soon'
            && str_contains($payload['body'], '@harbor.studio')
            && str_contains($payload['body'], now()->addDays(10)->toFormattedDateString());
    });
    $account->refresh();
    expect($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->reconnect_reminded_at)->not->toBeNull();
});

test('the proactive sweep reminds without refreshing again when a publish refreshed the tiktok account today', function () {
    $account = tikTokAccount(['refresh_token_expires_at' => now()->addDays(5), 'last_refreshed_at' => now()->subHours(2)]);
    Http::fake();
    Notification::fake();

    test()->artisan('accounts:refresh-tokens')->assertExitCode(0);

    Http::assertNothingSent();
    Notification::assertSentTo($account->attentionRecipients(), AccountReconnectDueNotification::class);
});

test('the proactive sweep flags a tiktok account TikTok no longer accepts', function (array $attributes) {
    $account = tikTokAccount(['last_refreshed_at' => now()->subDays(2), ...$attributes]);
    Http::fake([TIKTOK_TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.'])]);
    Notification::fake();

    test()->artisan('accounts:refresh-tokens')->assertExitCode(0);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention);
    Notification::assertSentTo($account->attentionRecipients(), AccountNeedsAttentionNotification::class);
    Notification::assertNotSentTo($account->attentionRecipients(), AccountReconnectDueNotification::class);
})->with([
    'refused ahead of its expiry' => [['refresh_token_expires_at' => now()->addDays(10)]],
    'run out after a reminder' => [['refresh_token_expires_at' => now()->subHour(), 'reconnect_reminded_at' => now()->subDays(10)]],
]);

test('a tiktok refresh clears a reconnect reminder only when TikTok issues a new refresh token', function (string $returnedRefreshToken, bool $stillReminded) {
    $account = tikTokAccount(['refresh_token_expires_at' => now()->addDays(5), 'reconnect_reminded_at' => now()->subDay()]);
    Http::fake([TIKTOK_TOKEN_URL => Http::response([
        'access_token' => 'new-access',
        'refresh_token' => $returnedRefreshToken,
        'expires_in' => 86400,
        'refresh_expires_in' => 5 * 86400,
    ])]);

    app(TokenManager::class)->fresh($account);

    expect($account->fresh()->reconnect_reminded_at !== null)->toBe($stillReminded);
})->with([
    'the same refresh token' => ['old-refresh', true],
    'a new refresh token' => ['new-refresh', false],
]);

test('a tiktok refresh keeps the stored refresh token expiry when TikTok leaves it out', function () {
    $expiresAt = now()->addDays(100)->startOfSecond();
    $account = tikTokAccount(['refresh_token_expires_at' => $expiresAt]);
    Http::fake([TIKTOK_TOKEN_URL => Http::response(['access_token' => 'new-access', 'refresh_token' => 'old-refresh', 'expires_in' => 86400])]);

    app(TokenManager::class)->fresh($account);

    expect($account->fresh()->refresh_token_expires_at->equalTo($expiresAt))->toBeTrue();
});

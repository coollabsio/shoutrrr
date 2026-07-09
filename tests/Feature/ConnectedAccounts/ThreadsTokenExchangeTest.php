<?php

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Services\ConnectedAccounts\Threads\ThreadsTokenExchanger;
use Illuminate\Support\Facades\Http;

test('exchangeForLongLived maps a short-lived token to a 60-day long-lived one', function () {
    config()->set('services.threads.client_secret', 'tsecret');

    Http::fake([
        'https://graph.threads.net/access_token*' => Http::response([
            'access_token' => 'long-token',
            'token_type' => 'bearer',
            'expires_in' => 5183944,
        ]),
    ]);

    $result = app(ThreadsTokenExchanger::class)->exchangeForLongLived('short-token');

    expect($result['token'])->toBe('long-token')
        ->and($result['expiresAt']->diffInDays(now(), true))->toBeGreaterThan(59)
        ->and($result['expiresAt']->diffInDays(now(), true))->toBeLessThan(61);

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/access_token?grant_type=th_exchange_token&client_secret=tsecret&access_token=short-token'
        && $request->method() === 'GET');
});

test('exchangeForLongLived throws when the exchange fails', function () {
    Http::fake([
        'https://graph.threads.net/access_token*' => Http::response(['error' => ['message' => 'bad token']], 400),
    ]);

    expect(fn () => app(ThreadsTokenExchanger::class)->exchangeForLongLived('short-token'))
        ->toThrow(TokenRefreshException::class);
});

test('refresh maps a long-lived token to a new long-lived one', function () {
    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([
            'access_token' => 'refreshed-token',
            'token_type' => 'bearer',
            'expires_in' => 5183944,
        ]),
    ]);

    $result = app(ThreadsTokenExchanger::class)->refresh('old-long-token');

    expect($result['token'])->toBe('refreshed-token')
        ->and($result['expiresAt']->diffInDays(now(), true))->toBeGreaterThan(59);

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/refresh_access_token?grant_type=th_refresh_token&access_token=old-long-token'
        && $request->method() === 'GET');
});

test('refresh throws when the refresh fails', function () {
    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([], 400),
    ]);

    expect(fn () => app(ThreadsTokenExchanger::class)->refresh('old-long-token'))
        ->toThrow(TokenRefreshException::class);
});

test('withLongLivedToken immutably replaces the access token and expiry', function () {
    $original = new ConnectedAccountData(
        platform: Platform::Threads,
        remoteAccountId: 'th-1',
        handle: 'thready',
        displayName: 'Ready Thready',
        avatarUrl: null,
        authMethod: 'oauth',
        accessToken: 'short-token',
        tokenExpiresAt: now()->addHour()->toImmutable(),
    );

    $expiresAt = now()->addDays(60)->toImmutable();
    $updated = $original->withLongLivedToken('long-token', $expiresAt);

    expect($updated)->not->toBe($original)
        ->and($updated->accessToken)->toBe('long-token')
        ->and($updated->tokenExpiresAt)->toBe($expiresAt)
        ->and($original->accessToken)->toBe('short-token')
        ->and($updated->remoteAccountId)->toBe('th-1')
        ->and($updated->handle)->toBe('thready');
});

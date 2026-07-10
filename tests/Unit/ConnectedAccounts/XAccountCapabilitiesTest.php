<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\XAccountCapabilities;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

test('it maps a free X account to the standard post length even with a blue badge', function () {
    expect(XAccountCapabilities::fromUserData([
        'subscription_type' => 'None',
        'verified_type' => 'blue',
    ]))->toBe([
        'x_premium' => false,
        'max_text_length' => 280,
        'max_video_duration_seconds' => 140,
        'verified_type' => 'blue',
        'x_subscription_tier' => 'free',
    ]);
});

test('it maps every X Premium subscription tier to the longer post length', function (string $subscriptionType, string $tier) {
    expect(XAccountCapabilities::fromUserData([
        'subscription_type' => $subscriptionType,
        'verified_type' => 'none',
    ]))->toBe([
        'x_premium' => true,
        'max_text_length' => 25_000,
        'max_video_duration_seconds' => 14_400,
        'verified_type' => 'none',
        'x_subscription_tier' => $tier,
    ]);
})->with([
    'Basic' => ['Basic', 'basic'],
    'Premium' => ['Premium', 'premium'],
    'PremiumPlus' => ['PremiumPlus', 'premium_plus'],
]);

test('it detects subscription type from the authenticated X user endpoint', function () {
    Date::setTestNow('2026-07-10 12:00:00 UTC');

    Http::fake([
        'https://api.x.com/2/users/me*' => Http::response([
            'data' => [
                'id' => '1',
                'subscription_type' => 'PremiumPlus',
                'verified_type' => 'none',
            ],
        ]),
    ]);

    $capabilities = app(XAccountCapabilities::class)->tryForAccessToken('tok');

    expect($capabilities)->toMatchArray([
        'x_premium' => true,
        'max_text_length' => 25_000,
        'max_video_duration_seconds' => 14_400,
        'verified_type' => 'none',
        'x_subscription_tier' => 'premium_plus',
        'x_subscription_checked_at' => '2026-07-10T12:00:00+00:00',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.x.com/2/users/me?user.fields=subscription_type%2Cverified%2Cverified_type'
        && $request->hasHeader('Authorization', 'Bearer tok'));
});

test('it distinguishes an unavailable lookup from a free account', function () {
    Http::fake([
        'https://api.x.com/2/users/me*' => Http::response([], 503),
    ]);

    expect(app(XAccountCapabilities::class)->tryForAccessToken('tok'))->toBeNull()
        ->and(app(XAccountCapabilities::class)->forAccessToken(''))->toBe([
            'x_premium' => false,
            'max_text_length' => 280,
            'max_video_duration_seconds' => 140,
            'verified_type' => null,
            'x_subscription_tier' => 'free',
        ]);
});

test('legacy badge-only capabilities do not grant a Premium post limit', function () {
    $account = new ConnectedAccount;
    $account->platform = Platform::X;
    $account->capabilities = [
        'x_premium' => true,
        'max_text_length' => 25_000,
        'verified_type' => 'blue',
    ];

    expect($account->xSubscriptionTier())->toBeNull()
        ->and($account->hasXPremium())->toBeFalse()
        ->and($account->maxTextLength())->toBe(280)
        ->and($account->maxVideoDurationSeconds())->toBe(140);
});

test('a detected X Premium tier grants the longer video duration', function () {
    $account = new ConnectedAccount;
    $account->platform = Platform::X;
    $account->capabilities = [
        'x_subscription_tier' => 'premium',
        'max_video_duration_seconds' => 14_400,
    ];

    expect($account->maxVideoDurationSeconds())->toBe(14_400);
});

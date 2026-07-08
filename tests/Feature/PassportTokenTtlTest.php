<?php

use Laravel\Passport\Passport;

test('access tokens are configured to expire in about 8 hours', function () {
    $expiresAt = now()->add(Passport::tokensExpireIn());

    expect($expiresAt->diffInHours(now(), absolute: true))->toBeGreaterThanOrEqual(7)
        ->toBeLessThanOrEqual(9);
});

test('refresh tokens are configured to expire in about 30 days', function () {
    $expiresAt = now()->add(Passport::refreshTokensExpireIn());

    expect($expiresAt->diffInDays(now(), absolute: true))->toBeGreaterThanOrEqual(29)
        ->toBeLessThanOrEqual(31);
});

test('personal access tokens default to an effectively non-expiring ~100 year horizon', function () {
    $expiresAt = now()->add(Passport::personalAccessTokensExpireIn());

    expect($expiresAt->diffInDays(now(), absolute: true))->toBeGreaterThanOrEqual(99 * 365);
});

<?php

use App\Enums\Platform;

test('platform capability flags are correct', function () {
    expect(Platform::X->supportsOAuth())->toBeTrue()
        ->and(Platform::X->supportsAppPassword())->toBeFalse()
        ->and(Platform::LinkedIn->supportsOAuth())->toBeTrue()
        ->and(Platform::LinkedIn->supportsAppPassword())->toBeFalse()
        ->and(Platform::Bluesky->supportsOAuth())->toBeFalse()
        ->and(Platform::Bluesky->supportsAppPassword())->toBeTrue();
});

test('x scopes include users.email so Socialite can read confirmed_email', function () {
    // Socialite's X driver always requests the confirmed_email user field, which
    // 403s unless the users.email scope was granted. Regression guard for that.
    expect(Platform::X->scopes())->toContain('users.email')
        ->and(Platform::X->scopes())->toContain('tweet.write')
        // media.write is required for v2 media upload (/2/media/upload).
        ->and(Platform::X->scopes())->toContain('media.write');
});

test('socialite driver names match core socialite keys', function () {
    expect(Platform::X->socialiteDriver())->toBe('x')
        ->and(Platform::LinkedIn->socialiteDriver())->toBe('linkedin-openid')
        ->and(Platform::Bluesky->socialiteDriver())->toBeNull();
});

test('oauth platform is configured only when client id and secret are present', function () {
    config()->set('services.x.client_id', null);
    config()->set('services.x.client_secret', null);
    expect(Platform::X->isConfigured())->toBeFalse();

    // The redirect URI is derived from the request at connect time (not config),
    // so credentials alone determine whether the connect button is usable.
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    expect(Platform::X->isConfigured())->toBeTrue();
});

test('oauth platform with blank credentials is not configured', function () {
    // env_file passthrough turns an unset var into an empty string, which must
    // not count as configured.
    config()->set('services.x.client_id', '');
    config()->set('services.x.client_secret', '');
    expect(Platform::X->isConfigured())->toBeFalse();

    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', '');
    expect(Platform::X->isConfigured())->toBeFalse();
});

test('app-password platform is always configured', function () {
    expect(Platform::Bluesky->isConfigured())->toBeTrue();
});

test('capabilities array exposes one entry per platform for the frontend', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');

    $caps = Platform::capabilities();

    expect($caps)->toHaveCount(3)
        ->and($caps[0])->toHaveKeys(['platform', 'label', 'supportsOAuth', 'supportsAppPassword', 'configured']);
});

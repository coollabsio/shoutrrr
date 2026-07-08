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

    expect($caps)->toHaveCount(6)
        ->and($caps[0])->toHaveKeys(['platform', 'label', 'supportsOAuth', 'supportsAppPassword', 'configured', 'launched']);
});

test('only platforms with implemented connectors are launched', function () {
    expect(Platform::X->isLaunched())->toBeTrue()
        ->and(Platform::Bluesky->isLaunched())->toBeTrue()
        ->and(Platform::LinkedIn->isLaunched())->toBeTrue()
        ->and(Platform::Facebook->isLaunched())->toBeFalse()
        ->and(Platform::Instagram->isLaunched())->toBeFalse()
        ->and(Platform::Threads->isLaunched())->toBeFalse();
});

test('meta platforms report oauth capability and no app password', function () {
    foreach ([Platform::Facebook, Platform::Instagram, Platform::Threads] as $platform) {
        expect($platform->supportsOAuth())->toBeTrue()
            ->and($platform->supportsAppPassword())->toBeFalse();
    }
});

test('meta socialite drivers and config keys are wired', function () {
    expect(Platform::Facebook->socialiteDriver())->toBe('facebook')
        ->and(Platform::Instagram->socialiteDriver())->toBe('facebook')
        ->and(Platform::Threads->socialiteDriver())->toBe('threads')
        ->and(Platform::Facebook->configKey())->toBe('services.facebook')
        ->and(Platform::Instagram->configKey())->toBe('services.facebook')
        ->and(Platform::Threads->configKey())->toBe('services.threads');
});

test('meta text limits and threading match the spec', function () {
    expect(Platform::Facebook->maxLength())->toBe(63_206)
        ->and(Platform::Instagram->maxLength())->toBe(2_200)
        ->and(Platform::Threads->maxLength())->toBe(500)
        ->and(Platform::Facebook->threadMax())->toBe(1)
        ->and(Platform::Instagram->threadMax())->toBe(1)
        ->and(Platform::Threads->threadMax())->toBeNull()
        ->and(Platform::Threads->measure('héllo'))->toBe(5);
});

test('instagram is configured off the shared facebook credentials', function () {
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    expect(Platform::Facebook->isConfigured())->toBeTrue()
        ->and(Platform::Instagram->isConfigured())->toBeTrue();

    config()->set('services.facebook.client_id', '');
    expect(Platform::Instagram->isConfigured())->toBeFalse();
});

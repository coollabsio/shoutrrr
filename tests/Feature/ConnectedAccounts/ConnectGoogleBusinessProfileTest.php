<?php

use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.google_business_profile.client_id', 'google-client');
    config()->set('services.google_business_profile.client_secret', 'google-secret');
    config()->set('services.google_business_profile.api_approved', true);
    app(InstanceSettings::class)->update(['google_business_profile_enabled' => true]);
});

test('redirect starts a Google authorization request with offline consent', function () {
    ownerActingIn();

    $response = test()->get(route('accounts.google-business-profile.redirect'));

    $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');

    $location = $response->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['scope'] ?? null)->toBe('https://www.googleapis.com/auth/business.manage')
        ->and($location)->toContain('access_type=offline')
        ->and($location)->toContain('prompt=consent')
        ->and(session('accounts.google_business_profile.oauth.state'))->toBeString();
});

test('store persists each eligible selected location with encrypted credentials and consent', function () {
    [$user] = ownerActingIn();

    session([
        'accounts.google_business_profile.oauth' => ['tokens' => [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]],
        'accounts.google_business_profile.locations' => ['locations' => [
            'accounts/one/locations/one' => ['key' => 'accounts/one/locations/one', 'accountResourceName' => 'accounts/one', 'locationResourceName' => 'accounts/one/locations/one', 'title' => 'Eligible', 'addressLabel' => '123 Main', 'canOperateLocalPost' => true],
            'accounts/one/locations/two' => ['key' => 'accounts/one/locations/two', 'title' => 'Also eligible', 'addressLabel' => null, 'canOperateLocalPost' => true],
        ]],
    ]);

    test()->post(route('accounts.google-business-profile.store'), [
        'selected' => ['accounts/one/locations/one', 'accounts/one/locations/two'],
        'consent' => true,
    ])->assertRedirect(route('accounts.index'));

    $accounts = ConnectedAccount::where('platform', 'google_business_profile')->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('remote_account_id')->all())->toContain('accounts/one/locations/one', 'accounts/one/locations/two')
        ->and($accounts->first()->secret->access_token)->toBe('access-token')
        ->and($accounts->first()->secret->refresh_token)->toBe('refresh-token')
        ->and($accounts->first()->capabilities['google_business_profile']['locationResourceName'])->toBe('accounts/one/locations/one')
        ->and($accounts->first()->capabilities['google_business_profile']['consent']['granted_by_user_id'])->toBe($user->id)
        ->and(session()->has('accounts.google_business_profile.oauth'))->toBeFalse()
        ->and(session()->has('accounts.google_business_profile.locations'))->toBeFalse();
});

test('callback rejects an invalid state without exchanging credentials', function () {
    ownerActingIn();
    session(['accounts.google_business_profile.oauth' => ['state' => 'expected-state']]);
    Http::fake();

    test()->get(route('accounts.google-business-profile.callback', ['code' => 'code', 'state' => 'wrong-state']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    Http::assertNothingSent();
});

test('callback refuses an authorization response without a refresh token', function () {
    ownerActingIn();
    session(['accounts.google_business_profile.oauth' => ['state' => 'expected-state']]);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived'])]);

    test()->get(route('accounts.google-business-profile.callback', ['code' => 'code', 'state' => 'expected-state']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    Http::assertSentCount(1);
});

test('callback renders browser-safe locations while retaining tokens only in session', function () {
    ownerActingIn();
    session(['accounts.google_business_profile.oauth' => ['state' => 'expected-state']]);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]),
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
            'accounts' => [['name' => 'accounts/one']],
        ]),
        'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/one/locations*' => Http::response([
            'locations' => [[
                'name' => 'locations/one',
                'title' => 'Eligible location',
                'metadata' => ['canOperateLocalPost' => true],
            ]],
        ]),
    ]);

    test()->get(route('accounts.google-business-profile.callback', ['code' => 'code', 'state' => 'expected-state']))
        ->assertInertia(fn ($page) => $page
            ->component('accounts/connect-google-business-profile', false)
            ->has('locations', 1)
            ->where('locations.0.key', 'accounts/one/locations/one')
            ->where('locations.0.accountResourceName', 'accounts/one')
            ->where('locations.0.locationResourceName', 'accounts/one/locations/one')
            ->missing('tokens')
            ->missing('access_token')
            ->missing('refresh_token'));

    expect(session('accounts.google_business_profile.oauth.tokens.access_token'))->toBe('access-token')
        ->and(session('accounts.google_business_profile.oauth.tokens.refresh_token'))->toBe('refresh-token')
        ->and(session('accounts.google_business_profile.locations.locations.accounts/one/locations/one'))->toBeArray();
});

test('store rejects an ineligible stashed location', function () {
    ownerActingIn();

    session([
        'accounts.google_business_profile.oauth' => ['tokens' => ['access_token' => 'access-token', 'refresh_token' => 'refresh-token']],
        'accounts.google_business_profile.locations' => ['locations' => [
            'accounts/one/locations/no-posts' => ['key' => 'accounts/one/locations/no-posts', 'title' => 'Not eligible', 'addressLabel' => null, 'canOperateLocalPost' => false],
        ]],
    ]);

    test()->post(route('accounts.google-business-profile.store'), [
        'selected' => ['accounts/one/locations/no-posts'],
        'consent' => true,
    ])->assertSessionHasErrors('selected');

    expect(ConnectedAccount::where('platform', 'google_business_profile')->exists())->toBeFalse();
});

test('disconnecting one Google Business Profile location keeps another location using the same grant', function () {
    [$user, $workspace] = ownerActingIn();

    $first = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
        'platform' => 'google_business_profile',
        'remote_account_id' => 'accounts/one/locations/one',
    ]);
    $second = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
        'platform' => 'google_business_profile',
        'remote_account_id' => 'accounts/one/locations/two',
    ]);
    $first->secret()->create(['access_token' => 'shared-access', 'refresh_token' => 'shared-grant']);
    $second->secret()->create(['access_token' => 'shared-access', 'refresh_token' => 'shared-grant']);

    test()->delete("/accounts/{$first->id}")->assertRedirect(route('accounts.index'));

    expect(ConnectedAccount::withoutGlobalScopes()->find($first->id))->toBeNull()
        ->and(ConnectedAccount::withoutGlobalScopes()->find($second->id))->not->toBeNull()
        ->and($second->fresh()->secret->refresh_token)->toBe('shared-grant');
});

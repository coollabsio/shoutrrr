<?php

use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Http;

// Reuses ownerActingIn() from tests/Pest.php (shared across the
// connected-accounts Feature suite). LinkedIn Pages run through a SEPARATE
// Community Management developer app (`services.linkedin-pages`), gated on the
// instance "LinkedIn Pages" (Community Management) toggle.

beforeEach(function () {
    config()->set('services.linkedin-pages.client_id', 'pages-cid');
    config()->set('services.linkedin-pages.client_secret', 'pages-secret');
    app(InstanceSettings::class)->update(['linkedin_community_management_enabled' => true]);
});

test('pages redirect sends the user to LinkedIn with the org scopes and pages app id', function () {
    ownerActingIn();

    $location = test()->get(route('accounts.linkedin-pages.redirect'))
        ->headers->get('Location');

    expect($location)->toStartWith('https://www.linkedin.com/oauth/v2/authorization')
        ->and($location)->toContain('client_id=pages-cid')
        ->and(urldecode((string) $location))->toContain('r_organization_social')
        ->and(urldecode((string) $location))->toContain('w_organization_social')
        ->and(urldecode((string) $location))->toContain('rw_organization_admin');
});

test('pages redirect 404s when the community management toggle is off', function () {
    ownerActingIn();
    app(InstanceSettings::class)->update(['linkedin_community_management_enabled' => false]);

    test()->get(route('accounts.linkedin-pages.redirect'))->assertNotFound();
});

test('pages redirect 404s when the dedicated pages app is not configured', function () {
    ownerActingIn();
    config()->set('services.linkedin-pages.client_id', null);

    test()->get(route('accounts.linkedin-pages.redirect'))->assertNotFound();
});

test('pages callback renders the org picker after the CM token exchange', function () {
    ownerActingIn();

    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'org-tok',
            'expires_in' => 5184000,
            'refresh_token' => 'org-ref',
            'scope' => 'r_organization_social w_organization_social rw_organization_admin',
        ]),
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response([
            'elements' => [['organizationTarget' => 'urn:li:organization:2414183']],
        ]),
        'https://api.linkedin.com/rest/organizations*' => Http::response([
            'results' => ['2414183' => ['id' => 2414183, 'localizedName' => 'Acme Inc', 'vanityName' => 'acme']],
            'statuses' => ['2414183' => 200],
        ]),
    ]);

    test()->get('/accounts/callback/linkedin-pages?code=abc&state=xyz')
        // The `accounts/connect-linkedin` picker page's existence check is skipped
        // (mirrors ConnectMetaTest): assert only the props it's handed.
        ->assertInertia(fn ($page) => $page
            ->component('accounts/connect-linkedin', false)
            ->has('organizations', 1)
            ->where('organizations.0.name', 'Acme Inc'));

    expect(ConnectedAccount::count())->toBe(0)          // nothing persisted until the user picks
        ->and(session('accounts.linkedin.connect.accessToken'))->toBe('org-tok');
});

test('pages callback redirects with an error when the member administers no pages', function () {
    ownerActingIn();

    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'org-tok',
            'expires_in' => 5184000,
            'scope' => 'r_organization_social',
        ]),
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => []]),
    ]);

    test()->get('/accounts/callback/linkedin-pages?code=abc')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(ConnectedAccount::count())->toBe(0);
});

test('pages callback redirects with an error when the token exchange fails', function () {
    ownerActingIn();

    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    test()->get('/accounts/callback/linkedin-pages?code=abc')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(ConnectedAccount::count())->toBe(0);
});

test('store persists the selected pages as organization accounts', function () {
    ownerActingIn();

    session(['accounts.linkedin.connect' => [
        'organizations' => ['2414183' => ['id' => '2414183', 'urn' => 'urn:li:organization:2414183', 'name' => 'Acme Inc', 'vanityName' => 'acme']],
        'accessToken' => 'org-tok',
        'refreshToken' => 'org-ref',
        'tokenExpiresAt' => null,
        'approvedScopes' => ['r_organization_social'],
    ]]);

    test()->post(route('accounts.linkedin.store'), [
        'selected' => [['type' => 'organization', 'id' => '2414183']],
    ])->assertRedirect(route('accounts.index'));

    $page = ConnectedAccount::where('remote_account_id', '2414183')->firstOrFail();

    expect($page->isLinkedInOrganization())->toBeTrue()
        ->and($page->display_name)->toBe('Acme Inc')
        ->and($page->handle)->toBe('acme')
        ->and($page->capabilities['linkedin_engagement'])->toBeTrue();
});

test('store gates page engagement capability off when the org scope was not granted', function () {
    ownerActingIn();

    session(['accounts.linkedin.connect' => [
        'organizations' => ['2414183' => ['id' => '2414183', 'urn' => 'urn:li:organization:2414183', 'name' => 'Acme Inc', 'vanityName' => 'acme']],
        'accessToken' => 'org-tok',
        'refreshToken' => 'org-ref',
        'tokenExpiresAt' => null,
        'approvedScopes' => [],
    ]]);

    test()->post(route('accounts.linkedin.store'), [
        'selected' => [['type' => 'organization', 'id' => '2414183']],
    ])->assertRedirect(route('accounts.index'));

    expect(ConnectedAccount::where('remote_account_id', '2414183')->firstOrFail()->capabilities['linkedin_engagement'])->toBeFalse();
});

test('store rejects a personal selection — pages flow is organization-only', function () {
    ownerActingIn();

    session(['accounts.linkedin.connect' => [
        'organizations' => ['2414183' => ['id' => '2414183', 'urn' => 'urn:li:organization:2414183', 'name' => 'Acme Inc', 'vanityName' => 'acme']],
        'accessToken' => 'org-tok',
        'refreshToken' => 'org-ref',
        'tokenExpiresAt' => null,
        'approvedScopes' => ['r_organization_social'],
    ]]);

    test()->post(route('accounts.linkedin.store'), [
        'selected' => [['type' => 'person']],
    ])->assertSessionHasErrors('selected.0.type');

    expect(ConnectedAccount::where('platform', 'linkedin')->count())->toBe(0);
});

test('store rejects an organization selection missing an id and persists nothing', function () {
    ownerActingIn();

    session(['accounts.linkedin.connect' => [
        'organizations' => ['2414183' => ['id' => '2414183', 'urn' => 'urn:li:organization:2414183', 'name' => 'Acme Inc', 'vanityName' => 'acme']],
        'accessToken' => 'org-tok',
        'refreshToken' => 'org-ref',
        'tokenExpiresAt' => null,
        'approvedScopes' => ['r_organization_social'],
    ]]);

    test()->post(route('accounts.linkedin.store'), [
        'selected' => [['type' => 'organization']],
    ])->assertSessionHasErrors('selected.0.id');

    expect(ConnectedAccount::where('platform', 'linkedin')->count())->toBe(0);
});

test('store rejects an organization id outside the stashed whitelist and persists nothing', function () {
    ownerActingIn();

    session(['accounts.linkedin.connect' => [
        'organizations' => ['2414183' => ['id' => '2414183', 'urn' => 'urn:li:organization:2414183', 'name' => 'Acme Inc', 'vanityName' => 'acme']],
        'accessToken' => 'org-tok',
        'refreshToken' => 'org-ref',
        'tokenExpiresAt' => null,
        'approvedScopes' => ['r_organization_social'],
    ]]);

    test()->post(route('accounts.linkedin.store'), [
        'selected' => [['type' => 'organization', 'id' => '9999999']],
    ])->assertSessionHasErrors('selected.0.id');

    expect(ConnectedAccount::where('platform', 'linkedin')->count())->toBe(0);
});

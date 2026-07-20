<?php

use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

// Reuses ownerActingIn() + fakeOAuthUser() from tests/Pest.php (shared across
// the connected-accounts Feature suite).

test('linkedin callback renders the page picker when organizations are administered', function () {
    ownerActingIn();
    app(InstanceSettings::class)->update(['linkedin_community_management_enabled' => true]);

    fakeOAuthUser('linkedin-openid', [
        'id' => 'PERSON1',
        'name' => 'Jane Member',
        'nickname' => 'jane',
        'approvedScopes' => ['r_member_social_feed', 'w_organization_social', 'rw_organization_admin'],
    ]);

    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response([
            'elements' => [['organizationTarget' => 'urn:li:organization:2414183']],
        ]),
        'https://api.linkedin.com/rest/organizations*' => Http::response([
            'results' => ['2414183' => ['id' => 2414183, 'localizedName' => 'Acme Inc', 'vanityName' => 'acme']],
            'statuses' => ['2414183' => 200],
        ]),
    ]);

    test()->get('/accounts/callback/linkedin?code=abc&state=xyz')
        // The `accounts/connect-linkedin` picker page is a later task; skip
        // Inertia's page-file existence check (mirrors ConnectMetaTest).
        ->assertInertia(fn ($page) => $page
            ->component('accounts/connect-linkedin', false)
            ->where('person.remoteAccountId', 'PERSON1')
            ->has('organizations', 1)
            ->where('organizations.0.name', 'Acme Inc'));

    expect(ConnectedAccount::count())->toBe(0); // nothing persisted until the user picks
});

test('linkedin callback stays single-step when the toggle is off', function () {
    ownerActingIn();

    fakeOAuthUser('linkedin-openid', ['id' => 'PERSON1', 'name' => 'Jane', 'nickname' => 'jane', 'approvedScopes' => []]);

    test()->get('/accounts/callback/linkedin?code=abc&state=xyz')->assertRedirect(route('accounts.index'));

    expect(ConnectedAccount::where('platform', 'linkedin')->count())->toBe(1);
});

test('store persists the personal profile and selected pages', function () {
    ownerActingIn();
    app(InstanceSettings::class)->update(['linkedin_community_management_enabled' => true]);

    session(['accounts.linkedin.connect' => [
        'person' => ['remoteAccountId' => 'PERSON1', 'handle' => 'jane', 'displayName' => 'Jane', 'avatarUrl' => null],
        'organizations' => ['2414183' => ['id' => '2414183', 'urn' => 'urn:li:organization:2414183', 'name' => 'Acme Inc', 'vanityName' => 'acme']],
        'accessToken' => 'tok',
        'refreshToken' => 'ref',
        'tokenExpiresAt' => null,
    ]]);

    test()->post(route('accounts.linkedin.store'), [
        'selected' => [['type' => 'person'], ['type' => 'organization', 'id' => '2414183']],
    ])->assertRedirect(route('accounts.index'));

    $person = ConnectedAccount::where('remote_account_id', 'PERSON1')->firstOrFail();
    $page = ConnectedAccount::where('remote_account_id', '2414183')->firstOrFail();

    expect($person->isLinkedInOrganization())->toBeFalse()
        ->and($page->isLinkedInOrganization())->toBeTrue()
        ->and($page->display_name)->toBe('Acme Inc');
});

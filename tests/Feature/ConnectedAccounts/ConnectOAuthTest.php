<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

function ownerActingIn(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

function fakeOAuthUser(string $driver, array $data): SocialiteUser
{
    $user = (new SocialiteUser)
        ->map([
            'id' => $data['id'],
            'nickname' => $data['nickname'] ?? null,
            'name' => $data['name'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ])
        ->setToken($data['token'] ?? 'tok');

    if (array_key_exists('refreshToken', $data) && $data['refreshToken'] !== null) {
        $user->setRefreshToken($data['refreshToken']);
    }

    if (array_key_exists('expiresIn', $data) && $data['expiresIn'] !== null) {
        $user->setExpiresIn($data['expiresIn']);
    }

    if (array_key_exists('approvedScopes', $data)) {
        $user->setApprovedScopes($data['approvedScopes']);
    }

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect('https://provider.test/oauth'));
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);

    return $user;
}

test('redirect sends an owner to the X provider when configured', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    ownerActingIn();
    fakeOAuthUser('x', ['id' => 'x-1']);

    test()->get('/accounts/connect/x')->assertRedirect('https://provider.test/oauth');
});

test('redirect 404s for an unconfigured platform', function () {
    config()->set('services.x.client_id', null);
    config()->set('services.x.client_secret', null);
    ownerActingIn();

    test()->get('/accounts/connect/x')->assertNotFound();
});

test('redirect 404s for an unknown or app-password platform', function () {
    ownerActingIn();
    test()->get('/accounts/connect/bluesky')->assertNotFound();
    test()->get('/accounts/connect/myspace')->assertNotFound();
});

test('redirect 404s for instagram on the generic route even though it is launched', function () {
    // Instagram is launched, but it shares a single Facebook Login + Page
    // selection flow with Facebook via MetaConnectionController. The
    // generic single-step per-platform route must never handle it.
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    ownerActingIn();

    test()->get('/accounts/connect/instagram')->assertNotFound();
});

test('redirect 404s for facebook on the generic route even though it is launched', function () {
    // Facebook is launched, but it shares a single Facebook Login + Page
    // selection flow with Instagram via MetaConnectionController. The
    // generic single-step per-platform route must never handle it.
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    ownerActingIn();

    test()->get('/accounts/connect/facebook')->assertNotFound();
});

test('callback persists an active X account with an encrypted token', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    [$user, $workspace] = ownerActingIn();
    fakeOAuthUser('x', [
        'id' => 'x-99',
        'nickname' => 'ada',
        'name' => 'Ada',
        'avatar' => 'https://x/a.png',
        'token' => 'access',
        'refreshToken' => 'refresh',
        'expiresIn' => 7200,
    ]);
    Http::fake([
        'https://api.twitter.com/2/users/me*' => Http::response([
            'data' => ['id' => 'x-99', 'verified_type' => 'none'],
        ]),
    ]);

    test()->get('/accounts/callback/x')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'x-99');
    expect($account)->not->toBeNull()
        ->and($account->platform)->toBe(Platform::X)
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->handle)->toBe('@ada')
        ->and($account->workspace_id)->toBe($workspace->id)
        ->and($account->secret->access_token)->toBe('access')
        ->and($account->capabilities)->toBe([
            'x_premium' => false,
            'max_text_length' => 280,
            'verified_type' => 'none',
        ]);
});

test('callback detects X premium accounts for long tweets', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    ownerActingIn();
    fakeOAuthUser('x', [
        'id' => 'x-premium',
        'nickname' => 'premium',
        'token' => 'access',
    ]);
    Http::fake([
        'https://api.twitter.com/2/users/me*' => Http::response([
            'data' => ['id' => 'x-premium', 'verified_type' => 'blue'],
        ]),
    ]);

    test()->get('/accounts/callback/x')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'x-premium');
    expect($account->hasXPremium())->toBeTrue()
        ->and($account->maxTextLength())->toBe(25_000);
});

test('callback maps a linkedin-openid user', function () {
    config()->set('services.linkedin-openid.client_id', 'cid');
    config()->set('services.linkedin-openid.client_secret', 'secret');
    config()->set('services.linkedin-openid.redirect', 'https://app.test/accounts/callback/linkedin');
    ownerActingIn();
    fakeOAuthUser('linkedin-openid', [
        'id' => 'sub-1',
        'nickname' => null,
        'name' => 'Grace Hopper',
        'expiresIn' => 5184000,
    ]);

    test()->get('/accounts/callback/linkedin')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'sub-1');
    expect($account->platform)->toBe(Platform::LinkedIn)
        ->and($account->handle)->toBe('Grace Hopper')
        ->and($account->token_expires_at)->not->toBeNull();
});

test('linkedin connect requests the community management feed scopes only when enabled', function () {
    config()->set('services.linkedin-openid.client_id', 'cid');
    config()->set('services.linkedin-openid.client_secret', 'secret');
    config()->set('services.linkedin-openid.redirect', 'https://app.test/accounts/callback/linkedin');
    ownerActingIn();

    $captured = [];
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnUsing(function (array $scopes) use ($provider, &$captured) {
        $captured = $scopes;

        return $provider;
    });
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect('https://provider.test/oauth'));
    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn($provider);

    // Off by default: never request the restricted scope (it would break authorize).
    test()->get('/accounts/connect/linkedin')->assertRedirect('https://provider.test/oauth');
    expect($captured)->not->toContain('r_member_social_feed');

    app(App\Support\InstanceSettings::class)->update(['linkedin_community_management_enabled' => true]);

    test()->get('/accounts/connect/linkedin')->assertRedirect('https://provider.test/oauth');
    expect($captured)->toContain('r_member_social_feed')
        ->and($captured)->toContain('w_member_social_feed');
});

test('linkedin connect records the engagement capability from the granted scopes', function () {
    config()->set('services.linkedin-openid.client_id', 'cid');
    config()->set('services.linkedin-openid.client_secret', 'secret');
    config()->set('services.linkedin-openid.redirect', 'https://app.test/accounts/callback/linkedin');
    ownerActingIn();
    fakeOAuthUser('linkedin-openid', [
        'id' => 'sub-cap',
        'name' => 'Ada Lovelace',
        'expiresIn' => 5184000,
        'approvedScopes' => ['openid', 'profile', 'email', 'w_member_social', 'r_member_social_feed'],
    ]);

    test()->get('/accounts/callback/linkedin')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'sub-cap');
    expect($account->capabilities['linkedin_engagement'])->toBeTrue()
        ->and($account->canFetchEngagement())->toBeTrue();
});

test('linkedin connect marks engagement unavailable when the feed scope is not granted', function () {
    config()->set('services.linkedin-openid.client_id', 'cid');
    config()->set('services.linkedin-openid.client_secret', 'secret');
    config()->set('services.linkedin-openid.redirect', 'https://app.test/accounts/callback/linkedin');
    ownerActingIn();
    fakeOAuthUser('linkedin-openid', [
        'id' => 'sub-nocap',
        'name' => 'Alan Turing',
        'expiresIn' => 5184000,
        'approvedScopes' => ['openid', 'profile', 'email', 'w_member_social'],
    ]);

    test()->get('/accounts/callback/linkedin')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'sub-nocap');
    expect($account->capabilities['linkedin_engagement'])->toBeFalse()
        ->and($account->canFetchEngagement())->toBeFalse();
});

test('duplicate callback after a successful OAuth connection keeps the success flash', function () {
    config()->set('services.linkedin-openid.client_id', 'cid');
    config()->set('services.linkedin-openid.client_secret', 'secret');
    config()->set('services.linkedin-openid.redirect', 'https://app.test/accounts/callback/linkedin');
    ownerActingIn();
    $oauthUser = (new SocialiteUser)
        ->map([
            'id' => 'sub-duplicate',
            'nickname' => null,
            'name' => 'Duplicate Callback',
            'avatar' => null,
        ])
        ->setToken('tok')
        ->setExpiresIn(5184000);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')
        ->once()
        ->andReturn($oauthUser)
        ->ordered();
    $provider->shouldReceive('user')
        ->once()
        ->andThrow(new InvalidStateException)
        ->ordered();
    Socialite::shouldReceive('driver')->with('linkedin-openid')->twice()->andReturn($provider);

    test()->get('/accounts/callback/linkedin')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', 'LinkedIn account connected.');

    test()->get('/accounts/callback/linkedin')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionMissing('error')
        ->assertSessionHas('success', 'LinkedIn account connected.');
});

test('a member is forbidden from connecting', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($user)->get('/accounts/connect/x')->assertForbidden();
});

test('callback surfaces a friendly message when the user declines on the provider', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    ownerActingIn();

    test()->get('/accounts/callback/x?error=access_denied')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'declined')
            && str_contains($message, 'X'));

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('callback maps a scope failure to a friendly permission message', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    ownerActingIn();

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('Missing required OAuth2 scopes: users.email'));
    Socialite::shouldReceive('driver')->with('x')->andReturn($provider);

    test()->get('/accounts/callback/x')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'permission'));

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

<?php

use App\Enums\SocialProvider;
use App\Exceptions\SocialAuthException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\SocialiteService;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

/**
 * Build a fake Socialite user and bind it to the mocked driver.
 *
 * @param  array<string, mixed>  $overrides
 * @param  string  $driver  The Socialite driver name the controller resolves
 *                          (e.g. 'google', 'x', 'linkedin-openid').
 */
function fakeSocialiteUser(array $overrides = [], string $driver = 'google'): SocialiteUser
{
    $data = array_merge([
        'id' => 'google-123',
        'name' => 'Ada Lovelace',
        'nickname' => 'ada',
        'email' => 'ada@example.com',
        'avatar' => 'https://example.com/ada.png',
        'email_verified' => true,
    ], $overrides);

    $user = Mockery::mock(SocialiteUser::class);
    $user->shouldReceive('getId')->andReturn($data['id']);
    $user->shouldReceive('getName')->andReturn($data['name']);
    $user->shouldReceive('getNickname')->andReturn($data['nickname']);
    $user->shouldReceive('getEmail')->andReturn($data['email']);
    $user->shouldReceive('getAvatar')->andReturn($data['avatar']);
    $user->user = ['email_verified' => $data['email_verified']];

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);

    return $user;
}

test('a social account belongs to a user and is unique per provider identity', function () {
    $user = User::factory()->create();

    $account = SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'abc-123',
    ]);

    expect($account->user->is($user))->toBeTrue();

    $duplicate = fn () => SocialAccount::factory()->create([
        'provider' => 'google',
        'provider_id' => 'abc-123',
    ]);

    expect($duplicate)->toThrow(QueryException::class);
});

test('user exposes login-method helpers', function () {
    $passwordUser = User::factory()->create();
    expect($passwordUser->hasPassword())->toBeTrue()
        ->and($passwordUser->loginMethodCount())->toBe(1);

    $oauthOnly = User::factory()->create(['password' => null]);
    expect($oauthOnly->hasPassword())->toBeFalse()
        ->and($oauthOnly->hasSocialAccount(SocialProvider::Google))->toBeFalse()
        ->and($oauthOnly->loginMethodCount())->toBe(0);

    SocialAccount::factory()->create([
        'user_id' => $oauthOnly->id,
        'provider' => 'google',
    ]);

    expect($oauthOnly->fresh()->hasSocialAccount(SocialProvider::Google))->toBeTrue()
        ->and($oauthOnly->fresh()->loginMethodCount())->toBe(1);
});

test('new oauth user is created, verified, and given a default workspace', function () {
    $oauthUser = fakeSocialiteUser(['email' => 'new@example.com']);

    $service = app(SocialiteService::class);
    $result = $service->loginOrRegister(SocialProvider::Google, $oauthUser, null);

    $user = $result->user;
    expect(User::where('email', 'new@example.com')->exists())->toBeTrue()
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->where('provider', 'google')->exists())->toBeTrue()
        ->and($user->workspaceMemberships()->count())->toBe(1)
        ->and($result->wasRegistered)->toBeTrue();
});

test('new oauth user with an invalid invitation still gets a default workspace', function () {
    $oauthUser = fakeSocialiteUser(['email' => 'invitee@example.com']);

    $result = app(SocialiteService::class)
        ->loginOrRegister(SocialProvider::Google, $oauthUser, 'totally-invalid-token');

    expect($result->wasRegistered)->toBeTrue()
        ->and($result->user->workspaceMemberships()->count())->toBe(1);
});

test('returning user is matched by provider id, not email', function () {
    $existing = User::factory()->create(['email' => 'real@example.com']);
    $existing->socialAccounts()->create([
        'provider' => 'google',
        'provider_id' => 'google-123',
        'name' => 'Old Name',
    ]);

    $oauthUser = fakeSocialiteUser(['id' => 'google-123', 'email' => 'changed@example.com']);

    $result = app(SocialiteService::class)->loginOrRegister(SocialProvider::Google, $oauthUser, null);

    expect($result->user->is($existing))->toBeTrue()
        ->and(User::count())->toBe(1)
        ->and($result->wasRegistered)->toBeFalse();
});

test('existing account is auto-linked when provider email is verified', function () {
    $existing = User::factory()->create(['email' => 'ada@example.com']);

    $oauthUser = fakeSocialiteUser(['email' => 'ada@example.com', 'email_verified' => true]);

    $result = app(SocialiteService::class)->loginOrRegister(SocialProvider::Google, $oauthUser, null);

    expect($result->user->is($existing))->toBeTrue()
        ->and($existing->socialAccounts()->where('provider', 'google')->exists())->toBeTrue();
});

test('existing account is NOT linked when provider email is unverified', function () {
    $existing = User::factory()->create(['email' => 'ada@example.com']);

    $oauthUser = fakeSocialiteUser(['email' => 'ada@example.com', 'email_verified' => false]);

    $call = fn () => app(SocialiteService::class)->loginOrRegister(SocialProvider::Google, $oauthUser, null);

    expect($call)->toThrow(SocialAuthException::class)
        ->and($existing->socialAccounts()->count())->toBe(0);
});

test('authenticated user can link a new provider', function () {
    $user = User::factory()->create();
    $oauthUser = fakeSocialiteUser(['id' => 'google-999']);

    app(SocialiteService::class)->linkToUser($user, SocialProvider::Google, $oauthUser);

    expect($user->socialAccounts()->where('provider_id', 'google-999')->exists())->toBeTrue();
});

test('linking is rejected when the identity belongs to another user', function () {
    $owner = User::factory()->create();
    $owner->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'google-777']);

    $other = User::factory()->create();
    $oauthUser = fakeSocialiteUser(['id' => 'google-777']);

    $call = fn () => app(SocialiteService::class)->linkToUser($other, SocialProvider::Google, $oauthUser);

    expect($call)->toThrow(SocialAuthException::class)
        ->and($other->socialAccounts()->count())->toBe(0);
});

test('new oauth user with unverified provider email is not email-verified', function () {
    $oauthUser = fakeSocialiteUser(['email' => 'unverified@example.com', 'email_verified' => false]);

    $result = app(SocialiteService::class)->loginOrRegister(SocialProvider::Google, $oauthUser, null);

    expect($result->wasRegistered)->toBeTrue()
        ->and($result->user->email)->toBe('unverified@example.com')
        ->and($result->user->email_verified_at)->toBeNull();
});

test('linking an identity already linked to the same user is a no-op', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'google-same']);

    $oauthUser = fakeSocialiteUser(['id' => 'google-same']);

    app(SocialiteService::class)->linkToUser($user, SocialProvider::Google, $oauthUser);

    expect($user->socialAccounts()->where('provider_id', 'google-same')->count())->toBe(1);
});

test('redirect endpoint sends the user to the provider', function () {
    fakeSocialiteUser();

    $this->get('/auth/google/redirect')
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

test('callback logs in a new user and redirects to the agentic home', function () {
    fakeSocialiteUser(['email' => 'fresh@example.com']);

    $this->get('/auth/google/callback')
        ->assertRedirect(route('agentic.home'));

    $this->assertAuthenticated();
    expect(User::where('email', 'fresh@example.com')->exists())->toBeTrue();
});

test('callback with unverified email collision redirects to login with an error', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    fakeSocialiteUser(['email' => 'taken@example.com', 'email_verified' => false]);

    $this->get('/auth/google/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

test('authenticated user linking via callback returns to connections', function () {
    $user = User::factory()->create();
    fakeSocialiteUser(['id' => 'google-link-1']);

    $this->actingAs($user)
        ->get('/auth/google/callback')
        ->assertRedirect(route('connections.edit'));

    expect($user->socialAccounts()->where('provider_id', 'google-link-1')->exists())->toBeTrue();
});

test('disabled or unknown provider returns 404', function () {
    config()->set('kit.auth.socialite.providers', []);

    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback')->assertNotFound();
    $this->get('/auth/myspace/redirect')->assertNotFound();
});

test('login and register pages receive the enabled providers prop with labels', function () {
    config()->set('kit.auth.socialite.providers', ['google']);

    $expected = [['provider' => 'google', 'label' => 'Google']];

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('providers', $expected),
        );

    $this->get(route('register'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('providers', $expected),
        );
});

test('login page forwards an invitation token from the query string', function () {
    $this->get(route('login', ['invitation' => 'inv-token-123']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('invitation', 'inv-token-123'),
        );
});

test('callback with a provider that shares no email redirects to login with an error', function () {
    fakeSocialiteUser(['email' => null]);

    $this->get('/auth/google/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('an X account is auto-linked via its confirmed email even without an email_verified flag', function () {
    $existing = User::factory()->create(['email' => 'ada@example.com']);

    // X sends a confirmed_email (mapped to email) but no email_verified flag.
    $oauthUser = fakeSocialiteUser(
        ['id' => 'x-1', 'email' => 'ada@example.com', 'email_verified' => false],
        'x',
    );

    $result = app(SocialiteService::class)->loginOrRegister(SocialProvider::X, $oauthUser, null);

    expect($result->user->is($existing))->toBeTrue()
        ->and($existing->socialAccounts()->where('provider', 'x')->exists())->toBeTrue();
});

test('callback logs in a new X user via the x driver', function () {
    config()->set('kit.auth.socialite.providers', ['google', 'x', 'linkedin']);
    fakeSocialiteUser(['id' => 'x-9', 'email' => 'xnew@example.com', 'email_verified' => false], 'x');

    $this->get('/auth/x/callback')->assertRedirect(route('agentic.home'));

    $this->assertAuthenticated();
    expect(User::where('email', 'xnew@example.com')->exists())->toBeTrue();
});

test('callback logs in a new LinkedIn user via the linkedin-openid driver', function () {
    config()->set('kit.auth.socialite.providers', ['google', 'x', 'linkedin']);
    fakeSocialiteUser(['id' => 'li-9', 'email' => 'linew@example.com'], 'linkedin-openid');

    $this->get('/auth/linkedin/callback')->assertRedirect(route('agentic.home'));

    $this->assertAuthenticated();
    expect(User::where('email', 'linew@example.com')
        ->whereHas('socialAccounts', fn ($q) => $q->where('provider', 'linkedin'))
        ->exists())->toBeTrue();
});

test('login redirect targets the auth callback url, not the connect callback', function () {
    config()->set('kit.auth.socialite.providers', ['google', 'x']);
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('redirectUrl')
        ->once()
        ->with(route('auth.socialite.callback', 'x'))
        ->andReturnSelf();
    $provider->shouldReceive('redirect')
        ->andReturn(redirect('https://x.com/i/oauth2/authorize'));

    Socialite::shouldReceive('driver')->with('x')->andReturn($provider);

    $this->get('/auth/x/redirect')->assertRedirect('https://x.com/i/oauth2/authorize');
});

test('login and register pages expose x and linkedin when enabled', function () {
    config()->set('kit.auth.socialite.providers', ['google', 'x', 'linkedin']);

    $expected = [
        ['provider' => 'google', 'label' => 'Google'],
        ['provider' => 'x', 'label' => 'X'],
        ['provider' => 'linkedin', 'label' => 'LinkedIn'],
    ];

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('providers', $expected),
        );
});

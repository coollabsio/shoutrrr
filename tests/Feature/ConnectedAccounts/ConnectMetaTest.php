<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

function metaOwnerActingIn(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
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

function fakeFacebookOAuthUser(string $token = 'short-token'): SocialiteUser
{
    $user = (new SocialiteUser)
        ->map(['id' => 'fb-user-1', 'name' => 'Ada'])
        ->setToken($token);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect('https://facebook.test/oauth'));
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('facebook')->andReturn($provider);

    return $user;
}

function fakeMetaGraphResponses(): void
{
    config()->set('services.facebook.graph_version', 'v25.0');

    Http::fake([
        '*/oauth/access_token*' => Http::response([
            'access_token' => 'LONG_USER_TOKEN',
            'expires_in' => 5183944,
        ]),
        '*/me/accounts*' => Http::response(['data' => [[
            'id' => 'PAGE1',
            'name' => 'My Page',
            'access_token' => 'PGT1',
            'instagram_business_account' => [
                'id' => 'IG1',
                'username' => 'myig',
                'profile_picture_url' => 'https://x/a.jpg',
            ],
        ]]]),
    ]);
}

test('redirect 404s when facebook is not configured', function () {
    config()->set('services.facebook.client_id', null);
    config()->set('services.facebook.client_secret', null);
    metaOwnerActingIn();

    test()->get(route('accounts.meta.redirect'))->assertNotFound();
});

test('redirect sends the user into the facebook oauth dance now that facebook is launched', function () {
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    metaOwnerActingIn();

    expect(Platform::launchedMetaGraphPlatforms())->toBe([Platform::Facebook, Platform::Instagram]);

    fakeFacebookOAuthUser();

    test()->get(route('accounts.meta.redirect'))
        ->assertRedirect('https://facebook.test/oauth');
});

test('callback stashes assets server-side and renders a browser-safe projection', function () {
    metaOwnerActingIn();
    fakeFacebookOAuthUser();
    fakeMetaGraphResponses();

    test()->get(route('accounts.meta.callback'))
        // The `accounts/connect-meta` selection screen is a frontend page a
        // later task builds; the backend contract this test proves (component
        // name + browser-safe projection shape) doesn't depend on that file
        // existing yet, so skip Inertia's page-file existence check.
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/connect-meta', false)
            ->has('assets', 1)
            ->where('assets.0.key', 'PAGE1')
            ->where('assets.0.pageId', 'PAGE1')
            ->where('assets.0.pageName', 'My Page')
            ->where('assets.0.igUserId', 'IG1')
            ->where('assets.0.igUsername', 'myig')
            ->where('assets.0.igAvatarUrl', 'https://x/a.jpg')
            // Instagram is launched (Task 6), so a Page with a linked IG
            // Professional account offers both platforms.
            ->where('assets.0.platforms', ['facebook', 'instagram'])
            ->missing('assets.0.pageAccessToken')
        );

    $stash = session('accounts.meta.connect');
    expect($stash['assets'])->toHaveCount(1)
        ->and($stash['assets']['PAGE1']['pageAccessToken'])->toBe('PGT1')
        ->and($stash['assets']['PAGE1']['pageName'])->toBe('My Page');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/oauth/access_token')
        && $request['fb_exchange_token'] === 'short-token');
});

test('callback surfaces a friendly message when facebook denies the connection', function () {
    metaOwnerActingIn();

    test()->get(route('accounts.meta.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'declined'));

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('callback surfaces a friendly message when the graph api fails', function () {
    metaOwnerActingIn();
    fakeFacebookOAuthUser();
    config()->set('services.facebook.graph_version', 'v25.0');

    Http::fake([
        '*/oauth/access_token*' => Http::response(['error' => ['message' => 'rate limited']], 429),
    ]);

    test()->get(route('accounts.meta.callback'))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, "couldn't retrieve"));

    expect(session('accounts.meta.connect'))->toBeNull()
        ->and(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('store rejects instagram for a page with no linked instagram account', function () {
    metaOwnerActingIn();

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [
            'PAGE1' => [
                'pageId' => 'PAGE1',
                'pageName' => 'My Page',
                'pageAccessToken' => 'PGT1',
                'igUserId' => null,
                'igUsername' => null,
                'igAvatarUrl' => null,
            ],
        ],
        'userTokenExpiresAt' => null,
    ]])->post(route('accounts.meta.store'), [
        'selected' => [
            ['assetKey' => 'PAGE1', 'platform' => 'instagram'],
        ],
    ])->assertSessionHasErrors('selected');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('store creates a facebook connected account now that facebook is launched', function () {
    expect(Platform::launchedMetaGraphPlatforms())->toBe([Platform::Facebook, Platform::Instagram]);

    metaOwnerActingIn();

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [
            'PAGE1' => [
                'pageId' => 'PAGE1',
                'pageName' => 'My Page',
                'pageAccessToken' => 'PGT1',
                'igUserId' => null,
                'igUsername' => null,
                'igAvatarUrl' => null,
            ],
        ],
        'userTokenExpiresAt' => null,
    ]])->post(route('accounts.meta.store'), [
        'selected' => [
            ['assetKey' => 'PAGE1', 'platform' => 'facebook'],
        ],
    ])->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', '1 account connected.');

    $account = ConnectedAccount::withoutGlobalScopes()->sole();
    expect($account->platform)->toBe(Platform::Facebook)
        ->and($account->remote_account_id)->toBe('PAGE1');
});

test('store creates an instagram connected account now that instagram is launched', function () {
    expect(Platform::launchedMetaGraphPlatforms())->toBe([Platform::Facebook, Platform::Instagram]);

    metaOwnerActingIn();

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [
            'PAGE1' => [
                'pageId' => 'PAGE1',
                'pageName' => 'My Page',
                'pageAccessToken' => 'PGT1',
                'igUserId' => 'IG1',
                'igUsername' => 'myig',
                'igAvatarUrl' => null,
            ],
        ],
        'userTokenExpiresAt' => null,
    ]])->post(route('accounts.meta.store'), [
        'selected' => [
            ['assetKey' => 'PAGE1', 'platform' => 'instagram'],
        ],
    ])->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', '1 account connected.');

    $account = ConnectedAccount::withoutGlobalScopes()->sole();
    expect($account->platform)->toBe(Platform::Instagram)
        ->and($account->remote_account_id)->toBe('IG1');
});

test('store rejects a threads selection since threads never uses the shared meta flow', function () {
    // Threads connects through the generic single-step OAuthConnectionController
    // (Task 6), not this Facebook-Login Page-selection flow — Threads is never a
    // member of launchedMetaGraphPlatforms(), regardless of its own launch state.
    metaOwnerActingIn();

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [
            'PAGE1' => [
                'pageId' => 'PAGE1',
                'pageName' => 'My Page',
                'pageAccessToken' => 'PGT1',
                'igUserId' => 'IG1',
                'igUsername' => 'myig',
                'igAvatarUrl' => null,
            ],
        ],
        'userTokenExpiresAt' => null,
    ]])->post(route('accounts.meta.store'), [
        'selected' => [
            ['assetKey' => 'PAGE1', 'platform' => 'threads'],
        ],
    ])->assertSessionHasErrors('selected.0.platform');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('store rejects an unknown asset key', function () {
    metaOwnerActingIn();

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [],
        'userTokenExpiresAt' => null,
    ]])->post(route('accounts.meta.store'), [
        'selected' => [
            ['assetKey' => 'BOGUS', 'platform' => 'facebook'],
        ],
    ])->assertSessionHasErrors('selected.0.assetKey');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

test('store is forbidden for a workspace member', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($user)->post(route('accounts.meta.store'), [
        'selected' => [
            ['assetKey' => 'PAGE1', 'platform' => 'facebook'],
        ],
    ])->assertForbidden();
});

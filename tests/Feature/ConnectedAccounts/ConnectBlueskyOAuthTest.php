<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ConnectedAccounts\BlueskyOAuthConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function blueskyOAuthOwner(): array
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

function fakeDefaultBlueskyOAuthDiscovery(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            $url === 'https://bsky.social/.well-known/oauth-protected-resource' => Http::response([], 404),
            $url === 'https://pds.example/.well-known/oauth-protected-resource' => Http::response([
                'authorization_servers' => ['https://bsky.social'],
            ]),
            $url === 'https://bsky.social/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://bsky.social',
                'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
                'token_endpoint' => 'https://bsky.social/oauth/token',
                'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
            ]),
            $url === 'https://bsky.social/oauth/par' => Http::response(['request_uri' => 'urn:request:123'], 201, ['DPoP-Nonce' => 'nonce-1']),
            $url === 'https://bsky.social/oauth/token' => Http::response([
                'access_token' => 'access-oauth',
                'refresh_token' => 'refresh-oauth',
                'expires_in' => 3600,
                'sub' => 'did:plc:abc',
                'scope' => 'atproto repo:app.bsky.feed.post repo:app.bsky.feed.like blob:*/* rpc:com.atproto.repo.uploadBlob?aud=*',
            ], 200, ['DPoP-Nonce' => 'nonce-2']),
            str_contains($url, 'com.atproto.identity.resolveHandle') => Http::response([
                'did' => 'did:plc:abc',
            ]),
            str_contains($url, 'plc.directory/did:plc:abc') => Http::response([
                'service' => [['type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example']],
            ]),
            str_contains($url, 'app.bsky.actor.getProfile') => Http::response([
                'handle' => 'ada.bsky.social',
                'displayName' => 'Ada',
                'avatar' => 'https://cdn.example/ada.jpg',
            ]),
            default => Http::response([], 404),
        };
    });
}

test('bluesky oauth client metadata is public', function () {
    test()->get(route('oauth.bluesky.metadata'))
        ->assertOk()
        ->assertJsonPath('dpop_bound_access_tokens', true)
        ->assertJsonPath('scope', 'atproto repo:app.bsky.feed.post repo:app.bsky.feed.like blob:*/* rpc:com.atproto.repo.uploadBlob?aud=*')
        ->assertJsonPath('token_endpoint_auth_method', 'private_key_jwt')
        ->assertJsonPath('token_endpoint_auth_signing_alg', 'ES256')
        ->assertJsonPath('jwks_uri', route('oauth.bluesky.jwks'));
});

test('an owner can start bluesky oauth with a handle that resolves their pds', function () {
    blueskyOAuthOwner();
    fakeDefaultBlueskyOAuthDiscovery();

    test()->get(route('accounts.bluesky.oauth', ['identifier' => 'ada.bsky.social']))
        ->assertRedirect('https://bsky.social/oauth/authorize?client_id='.urlencode(route('oauth.bluesky.metadata')).'&request_uri=urn%3Arequest%3A123');

    expect(session('accounts.bluesky.oauth'))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://bsky.social/oauth/par'
        && $request->hasHeader('DPoP')
        && $request['login_hint'] === 'ada.bsky.social'
        && $request['scope'] === 'atproto repo:app.bsky.feed.post repo:app.bsky.feed.like blob:*/* rpc:com.atproto.repo.uploadBlob?aud=*'
        && $request['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        && isset($request['client_assertion']));
});

test('an owner can start bluesky oauth without a handle', function () {
    blueskyOAuthOwner();
    fakeDefaultBlueskyOAuthDiscovery();

    test()->get(route('accounts.bluesky.oauth'))
        ->assertRedirect('https://bsky.social/oauth/authorize?client_id='.urlencode(route('oauth.bluesky.metadata')).'&request_uri=urn%3Arequest%3A123');

    expect(session('accounts.bluesky.oauth'))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://bsky.social/oauth/par'
        && $request->hasHeader('DPoP')
        && ! isset($request['login_hint'])
        && $request['scope'] === 'atproto repo:app.bsky.feed.post repo:app.bsky.feed.like blob:*/* rpc:com.atproto.repo.uploadBlob?aud=*');
});

test('bluesky oauth omits client_assertion for the public loopback dev client', function () {
    fakeDefaultBlueskyOAuthDiscovery();

    // The loopback client (a synthesized http://localhost/?… id, not the published
    // metadata document) is a public client and must not send a private_key_jwt.
    app(BlueskyOAuthConnector::class)->authorizationRedirect(
        null,
        'http://localhost/?redirect_uri=http://127.0.0.1/callback&scope=atproto',
        'http://127.0.0.1/callback',
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://bsky.social/oauth/par'
        && ! isset($request['client_assertion'])
        && ! isset($request['client_assertion_type']));
});

test('bluesky oauth can start from an advanced service url', function () {
    blueskyOAuthOwner();

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            $url === 'https://pds.example/.well-known/oauth-protected-resource' => Http::response([
                'authorization_servers' => ['https://auth.example'],
            ]),
            $url === 'https://auth.example/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.example',
                'authorization_endpoint' => 'https://auth.example/oauth/authorize',
                'token_endpoint' => 'https://auth.example/oauth/token',
                'pushed_authorization_request_endpoint' => 'https://auth.example/oauth/par',
            ]),
            $url === 'https://auth.example/oauth/par' => Http::response(['request_uri' => 'urn:request:456'], 201, ['DPoP-Nonce' => 'nonce-1']),
            default => Http::response([], 404),
        };
    });

    test()->get(route('accounts.bluesky.oauth', ['pds_url' => 'https://pds.example']))
        ->assertRedirect('https://auth.example/oauth/authorize?client_id='.urlencode(route('oauth.bluesky.metadata')).'&request_uri=urn%3Arequest%3A456');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example/oauth/par'
        && ! isset($request['login_hint']));
});

test('bluesky oauth binds an identifier to the expected did when using an advanced service url', function () {
    blueskyOAuthOwner();

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'com.atproto.identity.resolveHandle') => Http::response([
                'did' => 'did:plc:abc',
            ]),
            $url === 'https://pds.example/.well-known/oauth-protected-resource' => Http::response([
                'authorization_servers' => ['https://auth.example'],
            ]),
            $url === 'https://auth.example/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.example',
                'authorization_endpoint' => 'https://auth.example/oauth/authorize',
                'token_endpoint' => 'https://auth.example/oauth/token',
                'pushed_authorization_request_endpoint' => 'https://auth.example/oauth/par',
            ]),
            $url === 'https://auth.example/oauth/par' => Http::response(['request_uri' => 'urn:request:456'], 201, ['DPoP-Nonce' => 'nonce-1']),
            default => Http::response([], 404),
        };
    });

    test()->get(route('accounts.bluesky.oauth', [
        'identifier' => 'ada.bsky.social',
        'pds_url' => 'https://pds.example',
    ]))->assertRedirect('https://auth.example/oauth/authorize?client_id='.urlencode(route('oauth.bluesky.metadata')).'&request_uri=urn%3Arequest%3A456');

    $state = array_key_first(session('accounts.bluesky.oauth'));
    $context = session("accounts.bluesky.oauth.{$state}");

    expect($context['expected_did'])->toBe('did:plc:abc')
        ->and($context['pds'])->toBe('https://pds.example');
});

test('bluesky oauth fails closed when an advanced service url identifier cannot be verified', function () {
    blueskyOAuthOwner();

    Http::fake(fn () => Http::response([], 404));

    test()->from(route('accounts.index'))
        ->get(route('accounts.bluesky.oauth', [
            'identifier' => 'ada.bsky.social',
            'pds_url' => 'https://pds.example',
        ]))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(session('accounts.bluesky.oauth'))->toBeNull();
});

test('bluesky oauth rejects private discovered authorization servers', function () {
    blueskyOAuthOwner();

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            $url === 'https://pds.example/.well-known/oauth-protected-resource' => Http::response([
                'authorization_servers' => ['https://127.0.0.1'],
            ]),
            default => Http::response([], 404),
        };
    });

    test()->from(route('accounts.index'))
        ->get(route('accounts.bluesky.oauth', ['pds_url' => 'https://pds.example']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://127.0.0.1'));
});

test('bluesky oauth rejects unsafe metadata endpoint urls', function () {
    blueskyOAuthOwner();

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            $url === 'https://pds.example/.well-known/oauth-protected-resource' => Http::response([
                'authorization_servers' => ['https://auth.example'],
            ]),
            $url === 'https://auth.example/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.example',
                'authorization_endpoint' => 'https://auth.example/oauth/authorize',
                'token_endpoint' => 'https://localhost/oauth/token',
                'pushed_authorization_request_endpoint' => 'https://auth.example/oauth/par',
            ]),
            default => Http::response([], 404),
        };
    });

    test()->from(route('accounts.index'))
        ->get(route('accounts.bluesky.oauth', ['pds_url' => 'https://pds.example']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://auth.example/oauth/par');
});

test('bluesky oauth callback stores an oauth account', function () {
    [, $workspace] = blueskyOAuthOwner();
    fakeDefaultBlueskyOAuthDiscovery();

    test()->get(route('accounts.bluesky.oauth'));
    $state = array_key_first(session('accounts.bluesky.oauth'));

    test()->get(route('accounts.bluesky.oauth.callback', [
        'state' => $state,
        'code' => 'code-123',
        'iss' => 'https://bsky.social',
    ]))->assertRedirect(route('accounts.index'))->assertSessionHas('success');

    $account = ConnectedAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

    expect($account->platform)->toBe(Platform::Bluesky)
        ->and($account->auth_method)->toBe('oauth')
        ->and($account->handle)->toBe('@ada.bsky.social')
        ->and($account->secret->access_token)->toBe('access-oauth')
        ->and($account->secret->refresh_token)->toBe('refresh-oauth')
        ->and($account->secret->session)->toHaveKeys(['pds', 'auth_server', 'token_endpoint', 'dpop_private_jwk'])
        ->and($account->secret->session['pds'])->toBe('https://pds.example');
});

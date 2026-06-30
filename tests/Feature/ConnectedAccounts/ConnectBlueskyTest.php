<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;

function blueskyOwner(): array
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

function fakeBlueskySession(): void
{
    Http::fake([
        '*xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:abc',
            'handle' => 'ada.bsky.social',
            'accessJwt' => 'access-jwt',
            'refreshJwt' => 'refresh-jwt',
        ]),
        '*xrpc/app.bsky.actor.getProfile*' => Http::response([
            'did' => 'did:plc:abc',
            'handle' => 'ada.bsky.social',
            'displayName' => 'Ada',
            'avatar' => 'https://cdn/ada.jpg',
        ]),
    ]);
}

test('an owner connects a bluesky account with a sealed app password and session', function () {
    [$user, $workspace] = blueskyOwner();
    fakeBlueskySession();

    test()->post('/accounts/connect/bluesky', [
        'identifier' => 'ada.bsky.social',
        'app_password' => 'app-pass-1234',
        'pds_url' => 'https://bsky.social',
    ])->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'did:plc:abc');
    expect($account->platform)->toBe(Platform::Bluesky)
        ->and($account->auth_method)->toBe('app_password')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->handle)->toBe('@ada.bsky.social')
        ->and($account->secret->app_password)->toBe('app-pass-1234')
        ->and($account->secret->session)->toMatchArray(['accessJwt' => 'access-jwt']);
});

test('a leading at sign is removed from the submitted bluesky handle', function () {
    blueskyOwner();
    fakeBlueskySession();

    test()->post('/accounts/connect/bluesky', [
        'identifier' => '@ada.bsky.social',
        'app_password' => 'app-pass-1234',
        'pds_url' => 'https://bsky.social',
    ])->assertRedirect(route('accounts.index'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'com.atproto.server.createSession')
        && $request['identifier'] === 'ada.bsky.social');
});

test('a member cannot connect a bluesky account', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($user)->post('/accounts/connect/bluesky', [
        'identifier' => 'ada.bsky.social',
        'app_password' => 'x',
    ])->assertForbidden();
});

test('bad bluesky credentials redirect back with an error', function () {
    blueskyOwner();
    Http::fake(['*xrpc/com.atproto.server.createSession' => Http::response([], 401)]);

    test()->post('/accounts/connect/bluesky', [
        'identifier' => 'ada.bsky.social',
        'app_password' => 'wrong',
    ])->assertRedirect()->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
});

<?php

use App\Enums\Platform;
use App\Http\Controllers\ConnectedAccounts\OAuthConnectionController;
use App\Models\ConnectedAccount;

// ownerActingIn() + fakeOAuthUser() are shared helpers defined in tests/Pest.php.

test('x scopes include dm scopes when direct messages enabled', function () {
    config()->set('messages.direct_messages_enabled', true);

    $controller = app(OAuthConnectionController::class);
    $scopes = (fn () => $this->scopesFor(Platform::X))->call($controller);

    expect($scopes)->toContain('dm.read')->toContain('dm.write');
});

test('x scopes exclude dm scopes when disabled', function () {
    config()->set('messages.direct_messages_enabled', false);
    $controller = app(OAuthConnectionController::class);
    $scopes = (fn () => $this->scopesFor(Platform::X))->call($controller);
    expect($scopes)->not->toContain('dm.read');
});

test('x callback records dm_enabled true when the dm scopes are granted', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    config()->set('messages.direct_messages_enabled', true);
    ownerActingIn();
    fakeOAuthUser('x', [
        'id' => 'x-dm-yes',
        'nickname' => 'dmyes',
        'token' => 'access',
        'approvedScopes' => ['users.read', 'tweet.read', 'dm.read', 'dm.write'],
    ]);

    test()->get('/accounts/callback/x')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'x-dm-yes');
    expect($account->capabilities['dm_enabled'])->toBeTrue()
        ->and($account->canReceiveDirectMessages())->toBeTrue();
});

test('x callback records dm_enabled false when the dm scopes are not granted', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    config()->set('messages.direct_messages_enabled', true);
    ownerActingIn();
    fakeOAuthUser('x', [
        'id' => 'x-dm-no',
        'nickname' => 'dmno',
        'token' => 'access',
        'approvedScopes' => ['users.read', 'tweet.read'],
    ]);

    test()->get('/accounts/callback/x')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'x-dm-no');
    expect($account->capabilities['dm_enabled'])->toBeFalse()
        ->and($account->canReceiveDirectMessages())->toBeFalse();
});

test('x callback keeps the dm capability alongside the existing tier capabilities', function () {
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
    config()->set('messages.direct_messages_enabled', true);
    ownerActingIn();
    fakeOAuthUser('x', [
        'id' => 'x-dm-merge',
        'nickname' => 'dmmerge',
        'token' => 'access',
        'approvedScopes' => ['users.read', 'tweet.read', 'dm.read', 'dm.write'],
    ]);
    Illuminate\Support\Facades\Http::fake([
        'https://api.x.com/2/users/me*' => Illuminate\Support\Facades\Http::response([
            'data' => ['id' => 'x-dm-merge', 'subscription_type' => 'None', 'verified_type' => 'none'],
        ]),
    ]);

    test()->get('/accounts/callback/x')->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'x-dm-merge');
    expect($account->capabilities)->toMatchArray([
        'dm_enabled' => true,
        'x_subscription_tier' => 'free',
    ]);
});

test('bluesky connect records dm_enabled from the dm_access checkbox', function () {
    $user = App\Models\User::factory()->create();
    $workspace = App\Models\Workspace::factory()->create(['owner_id' => $user->id]);
    App\Models\WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => App\Enums\WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    Illuminate\Support\Facades\Http::fake([
        '*xrpc/com.atproto.server.createSession' => Illuminate\Support\Facades\Http::response([
            'did' => 'did:plc:dm',
            'handle' => 'dm.bsky.social',
            'accessJwt' => 'access-jwt',
            'refreshJwt' => 'refresh-jwt',
        ]),
        '*xrpc/app.bsky.actor.getProfile*' => Illuminate\Support\Facades\Http::response([
            'did' => 'did:plc:dm',
            'handle' => 'dm.bsky.social',
        ]),
    ]);

    test()->post('/accounts/connect/bluesky', [
        'identifier' => 'dm.bsky.social',
        'app_password' => 'app-pass-1234',
        'dm_access' => true,
    ])->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'did:plc:dm');
    expect($account->capabilities['dm_enabled'])->toBeTrue()
        ->and($account->canReceiveDirectMessages())->toBeTrue();
});

test('bluesky connect defaults dm_enabled to false without the checkbox', function () {
    $user = App\Models\User::factory()->create();
    $workspace = App\Models\Workspace::factory()->create(['owner_id' => $user->id]);
    App\Models\WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => App\Enums\WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    Illuminate\Support\Facades\Http::fake([
        '*xrpc/com.atproto.server.createSession' => Illuminate\Support\Facades\Http::response([
            'did' => 'did:plc:nodm',
            'handle' => 'nodm.bsky.social',
            'accessJwt' => 'access-jwt',
            'refreshJwt' => 'refresh-jwt',
        ]),
        '*xrpc/app.bsky.actor.getProfile*' => Illuminate\Support\Facades\Http::response([
            'did' => 'did:plc:nodm',
            'handle' => 'nodm.bsky.social',
        ]),
    ]);

    test()->post('/accounts/connect/bluesky', [
        'identifier' => 'nodm.bsky.social',
        'app_password' => 'app-pass-1234',
    ])->assertRedirect(route('accounts.index'));

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'did:plc:nodm');
    expect($account->capabilities['dm_enabled'])->toBeFalse()
        ->and($account->canReceiveDirectMessages())->toBeFalse();
});

<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;

function ownerWithWorkspace(): array
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

test('reconnecting a bluesky account preserves its id and clears needs_attention', function () {
    [$user, $workspace] = ownerWithWorkspace();

    $account = ConnectedAccount::factory()->bluesky()->needsAttention()->create([
        'workspace_id' => $workspace->id,
        'remote_account_id' => 'did:plc:abc',
        'connected_by_user_id' => $user->id,
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);

    Http::fake([
        '*xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:abc',
            'handle' => 'ada.bsky.social',
            'accessJwt' => 'a',
            'refreshJwt' => 'r',
        ]),
        '*xrpc/app.bsky.actor.getProfile*' => Http::response([
            'did' => 'did:plc:abc',
            'handle' => 'ada.bsky.social',
            'displayName' => 'Ada',
        ]),
    ]);

    test()->post("/accounts/{$account->id}/reconnect", [
        'identifier' => 'ada.bsky.social',
        'app_password' => 'fresh-pass',
        'pds_url' => 'https://bsky.social',
    ])->assertRedirect(route('accounts.index'));

    $fresh = $account->fresh();
    expect($fresh->id)->toBe($account->id)
        ->and($fresh->status)->toBe(ConnectedAccountStatus::Active)
        ->and(ConnectedAccount::withoutGlobalScopes()->count())->toBe(1);
});

test('reconnect is rejected when the submitted credentials resolve to a different account', function () {
    [$user, $workspace] = ownerWithWorkspace();
    $account = ConnectedAccount::factory()->bluesky()->create([
        'workspace_id' => $workspace->id,
        'remote_account_id' => 'did:plc:original',
    ]);

    Http::fake([
        '*xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:different',
            'handle' => 'someone.bsky.social',
            'accessJwt' => 'a',
        ]),
        '*xrpc/app.bsky.actor.getProfile*' => Http::response([
            'did' => 'did:plc:different',
            'handle' => 'someone.bsky.social',
        ]),
    ]);

    test()->post("/accounts/{$account->id}/reconnect", [
        'identifier' => 'someone.bsky.social',
        'app_password' => 'pass',
    ])->assertRedirect()->assertSessionHasErrors('identifier');
});

test('reconnecting a facebook account restarts the shared meta login flow, not the generic route', function () {
    [$user, $workspace] = ownerWithWorkspace();
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook->value,
        'connected_by_user_id' => $user->id,
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);

    test()->post("/accounts/{$account->id}/reconnect")
        ->assertRedirect(route('accounts.meta.redirect'));
});

test('disconnect removes both the account and its secret row', function () {
    [$user, $workspace] = ownerWithWorkspace();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);

    test()->delete("/accounts/{$account->id}")
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success');

    expect(ConnectedAccount::withoutGlobalScopes()->find($account->id))->toBeNull()
        ->and(ConnectedAccountSecret::find($account->id))->toBeNull();
});

test('a member cannot disconnect an account', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $member = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => WorkspaceRole::Member,
    ]);
    $member->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($member)->delete("/accounts/{$account->id}")->assertForbidden();
});

test('an account from another workspace is not found scoped out', function () {
    ownerWithWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $foreign = ConnectedAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    test()->delete("/accounts/{$foreign->id}")->assertNotFound();
});

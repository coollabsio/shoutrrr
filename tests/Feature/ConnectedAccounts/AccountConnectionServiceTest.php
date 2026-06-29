<?php

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Events\ConnectedAccountConnected;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ConnectedAccounts\AccountConnectionService;
use Illuminate\Support\Facades\Event;
use Inertia\Support\SessionKey;

function makeOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace];
}

test('store persists an account, its secret, marks it active, and fires the event', function () {
    Event::fake([ConnectedAccountConnected::class]);
    [$user, $workspace] = makeOwner();

    $data = new ConnectedAccountData(
        platform: Platform::X,
        remoteAccountId: 'x-1',
        handle: '@ada',
        displayName: 'Ada',
        avatarUrl: 'https://x/a.png',
        authMethod: 'oauth',
        accessToken: 'tok',
        refreshToken: 'ref',
    );

    $account = app(AccountConnectionService::class)->store($data, $user, $workspace->id);

    expect($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->workspace_id)->toBe($workspace->id)
        ->and($account->connected_by_user_id)->toBe($user->id)
        ->and($account->last_refreshed_at)->not->toBeNull()
        ->and($account->secret->access_token)->toBe('tok')
        ->and($account->secret->refresh_token)->toBe('ref');

    Event::assertDispatched(ConnectedAccountConnected::class);
});

test('store clears stale inertia history after connecting an account', function () {
    [$user, $workspace] = makeOwner();

    $data = new ConnectedAccountData(
        platform: Platform::LinkedIn,
        remoteAccountId: 'linkedin-1',
        handle: 'Ada',
        displayName: 'Ada',
        avatarUrl: null,
        authMethod: 'oauth',
        accessToken: 'tok',
    );

    app(AccountConnectionService::class)->store($data, $user, $workspace->id);

    expect(session()->get(SessionKey::CLEAR_HISTORY))->toBeTrue();
});

test('store upserts an existing remote account, preserving id and clearing needs_attention', function () {
    [$user, $workspace] = makeOwner();

    $existing = ConnectedAccount::factory()->needsAttention()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'remote_account_id' => 'x-1',
        'handle' => '@old',
        'refresh_failed_at' => now(),
        'refresh_failure_reason' => 'HTTP 400: invalid_grant',
    ]);

    $data = new ConnectedAccountData(
        platform: Platform::X,
        remoteAccountId: 'x-1',
        handle: '@new',
        displayName: 'Ada',
        avatarUrl: null,
        authMethod: 'oauth',
        accessToken: 'tok2',
    );

    $account = app(AccountConnectionService::class)->store($data, $user, $workspace->id);

    expect($account->id)->toBe($existing->id)
        ->and($account->handle)->toBe('@new')
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->refresh_failed_at)->toBeNull()
        ->and($account->refresh_failure_reason)->toBeNull()
        ->and(ConnectedAccount::withoutGlobalScopes()->count())->toBe(1);
});

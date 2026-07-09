<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Publishing\PublishConnectorRegistry;
use Illuminate\Support\Facades\Http;

/**
 * The positive path deferred from Task 2's ConnectMetaTest guard tests: with
 * Facebook launched, a stashed Page selection actually creates a
 * ConnectedAccount + secret, and the resulting account can publish through
 * the registered FacebookConnector.
 */
function facebookOwnerActingIn(): array
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

test('posting a stashed page selection creates a facebook connected account and secret', function () {
    facebookOwnerActingIn();

    expect(Platform::launchedMetaGraphPlatforms())->toBe([Platform::Facebook, Platform::Instagram]);

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [
            'PAGE1' => [
                'pageId' => 'PAGE1',
                'pageName' => 'My Page',
                'pageAccessToken' => 'PAGE-TOKEN-1',
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
        ->and($account->remote_account_id)->toBe('PAGE1')
        ->and($account->handle)->toBe('My Page')
        ->and($account->auth_method)->toBe('oauth')
        ->and($account->secret)->not->toBeNull()
        ->and($account->secret->access_token)->toBe('PAGE-TOKEN-1');

    // The session stash is cleared after a successful store.
    expect(session('accounts.meta.connect'))->toBeNull();
});

test('the freshly connected facebook page can publish through the registered connector', function () {
    [, $workspace] = facebookOwnerActingIn();

    $target = PostTarget::factory()->create(['platform' => Platform::Facebook->value]);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/page123/feed' => Http::response(['id' => 'page123_555']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['hello from the launched facebook connector'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );

    $result = app(PublishConnectorRegistry::class)->for(Platform::Facebook)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['page123_555']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/page123/feed')
        && $request['message'] === 'hello from the launched facebook connector');
});

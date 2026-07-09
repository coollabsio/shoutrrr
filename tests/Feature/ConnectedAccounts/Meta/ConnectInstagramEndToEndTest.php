<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Publishing\PublishConnectorRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The positive path deferred from Task 2's ConnectMetaTest guard tests: with
 * Instagram launched, a stashed Page selection with a linked IG Professional
 * account actually creates a ConnectedAccount + secret keyed off the Page
 * token, and the resulting account can publish through the registered
 * InstagramConnector.
 */
function instagramOwnerActingIn(): array
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

test('posting a stashed page selection creates an instagram connected account and secret', function () {
    instagramOwnerActingIn();

    expect(Platform::launchedMetaGraphPlatforms())->toBe([Platform::Facebook, Platform::Instagram]);

    test()->withSession(['accounts.meta.connect' => [
        'assets' => [
            'PAGE1' => [
                'pageId' => 'PAGE1',
                'pageName' => 'My Page',
                'pageAccessToken' => 'PAGE-TOKEN-1',
                'igUserId' => 'IG1',
                'igUsername' => 'myig',
                'igAvatarUrl' => 'https://x/a.jpg',
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
        ->and($account->remote_account_id)->toBe('IG1')
        ->and($account->handle)->toBe('@myig')
        ->and($account->auth_method)->toBe('oauth')
        ->and($account->capabilities)->toBe(['page_id' => 'PAGE1'])
        ->and($account->secret)->not->toBeNull()
        // IG publishing/comments/insights all authenticate with the linked
        // Page's token, not an IG-specific one.
        ->and($account->secret->access_token)->toBe('PAGE-TOKEN-1');

    // The session stash is cleared after a successful store.
    expect(session('accounts.meta.connect'))->toBeNull();
});

test('the freshly connected instagram account can publish through the registered connector', function () {
    [, $workspace] = instagramOwnerActingIn();

    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    $target = PostTarget::factory()->create(['platform' => Platform::Instagram->value]);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram->value,
        'remote_account_id' => 'ig123',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'container-1']),
        'https://graph.facebook.com/*/container-1*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'media-999']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['hello from the launched instagram connector'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );

    $result = app(PublishConnectorRegistry::class)->for(Platform::Instagram)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['media-999']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && $request['caption'] === 'hello from the launched instagram connector');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig123/media_publish')
        && $request['creation_id'] === 'container-1');
});

<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Publishing\PublishConnectorRegistry;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Threads is the only platform that connects through the generic
 * OAuthConnectionController (its socialiteDriver() is 'threads' and
 * usesMetaConnectionFlow() is false) plus a bespoke long-lived-token-exchange
 * branch bolted onto that controller's callback(). This is the positive path
 * deferred from earlier tasks: with Threads launched, the generic route
 * actually creates a ConnectedAccount carrying the LONG-lived token, and a
 * failed exchange fails gracefully instead of 500ing. A publish smoke test
 * exercises the registered ThreadsConnector end to end.
 */
function threadsOwnerActingIn(): array
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

function fakeThreadsOAuthUser(array $data): SocialiteUser
{
    $user = (new SocialiteUser)
        ->map([
            'id' => $data['id'],
            'nickname' => $data['nickname'] ?? null,
            'name' => $data['name'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ])
        ->setToken($data['token'] ?? 'short-lived-tok');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect('https://threads.net/oauth/authorize?client_id=threads-cid'));
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('threads')->andReturn($provider);

    return $user;
}

test('redirect sends an owner to threads.net when threads is configured', function () {
    config()->set('services.threads.client_id', 'threads-cid');
    config()->set('services.threads.client_secret', 'threads-secret');
    config()->set('services.threads.redirect', 'https://app.test/accounts/callback/threads');
    threadsOwnerActingIn();
    fakeThreadsOAuthUser(['id' => 'threads-1']);

    test()->get('/accounts/connect/threads')
        ->assertRedirect('https://threads.net/oauth/authorize?client_id=threads-cid');
});

test('callback exchanges the short-lived token for a long-lived one and persists a threads account', function () {
    config()->set('services.threads.client_id', 'threads-cid');
    config()->set('services.threads.client_secret', 'threads-secret');
    config()->set('services.threads.redirect', 'https://app.test/accounts/callback/threads');
    [, $workspace] = threadsOwnerActingIn();
    fakeThreadsOAuthUser([
        'id' => 'threads-99',
        'nickname' => 'ada.threads',
        'name' => 'Ada Threads',
        'avatar' => 'https://threads.example/ada.jpg',
        'token' => 'short-lived-tok',
    ]);
    Http::fake([
        'https://graph.threads.net/access_token*' => Http::response([
            'access_token' => 'long-lived-tok',
            'token_type' => 'bearer',
            'expires_in' => 5_183_944, // ~60 days
        ]),
    ]);

    test()->get('/accounts/callback/threads')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('success', 'Threads account connected.');

    $account = ConnectedAccount::withoutGlobalScopes()->firstWhere('remote_account_id', 'threads-99');
    expect($account)->not->toBeNull()
        ->and($account->platform)->toBe(Platform::Threads)
        ->and($account->status)->toBe(ConnectedAccountStatus::Active)
        ->and($account->handle)->toBe('ada.threads')
        ->and($account->workspace_id)->toBe($workspace->id)
        // The persisted token must be the LONG-lived one from the exchange,
        // not the short-lived token Socialite handed back from the callback.
        ->and($account->secret->access_token)->toBe('long-lived-tok')
        ->and($account->token_expires_at)->not->toBeNull()
        ->and($account->token_expires_at->diffInDays(now(), true))->toBeGreaterThan(59)
        ->and($account->token_expires_at->diffInDays(now(), true))->toBeLessThan(61);

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/access_token?grant_type=th_exchange_token&client_secret=threads-secret&access_token=short-lived-tok');
});

test('callback redirects with a friendly error instead of 500ing when the long-lived exchange fails', function () {
    config()->set('services.threads.client_id', 'threads-cid');
    config()->set('services.threads.client_secret', 'threads-secret');
    config()->set('services.threads.redirect', 'https://app.test/accounts/callback/threads');
    threadsOwnerActingIn();
    fakeThreadsOAuthUser([
        'id' => 'threads-fail',
        'nickname' => 'failure.case',
        'token' => 'short-lived-tok',
    ]);
    Http::fake([
        'https://graph.threads.net/access_token*' => Http::response(['error' => ['message' => 'bad token']], 400),
    ]);

    test()->get('/accounts/callback/threads')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->where('remote_account_id', 'threads-fail')->exists())->toBeFalse();
});

test('the freshly connected threads account can publish through the registered connector', function () {
    [, $workspace] = threadsOwnerActingIn();

    $target = PostTarget::factory()->create(['platform' => Platform::Threads->value]);
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Threads->value,
        'remote_account_id' => 'threads123',
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'container-1']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'post-1']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['hello from the launched threads connector'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'threads-tok'],
    );

    $result = app(PublishConnectorRegistry::class)->for(Platform::Threads)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['text'] === 'hello from the launched threads connector');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/threads123/threads_publish')
        && $request['creation_id'] === 'container-1');
});

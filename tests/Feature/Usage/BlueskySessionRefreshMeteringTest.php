<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;

function makeBlueskyAppPasswordAccount(Workspace $workspace, string $refreshJwt = 'rjwt'): ConnectedAccount
{
    $account = ConnectedAccount::factory()->bluesky()->for($workspace)->create([
        'token_expires_at' => null,
        'remote_account_id' => 'did:plc:abc123',
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => 'app-pass',
        'session' => ['accessJwt' => 'stale-jwt', 'refreshJwt' => $refreshJwt, 'pds' => 'https://bsky.social'],
    ]);

    return $account->fresh();
}

it('records a token_refresh event when the bluesky refresh session call succeeds', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = makeBlueskyAppPasswordAccount($workspace);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
            'accessJwt' => 'fresh-jwt',
            'refreshJwt' => 'fresh-rjwt',
        ]),
    ]);

    app(TokenManager::class)->fresh($account);

    $event = UsageEvent::where('operation', 'token_refresh')
        ->where('platform', Platform::Bluesky->value)
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->succeeded)->toBeTrue()
        ->and($event->meta['status'])->toBe(200);
});

it('records a failed token_refresh event when the bluesky refresh session call fails', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = makeBlueskyAppPasswordAccount($workspace);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'ExpiredToken'], 400),
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'accessJwt' => 'login-jwt',
            'refreshJwt' => 'login-rjwt',
        ]),
    ]);

    app(TokenManager::class)->fresh($account);

    $events = UsageEvent::where('operation', 'token_refresh')
        ->where('platform', Platform::Bluesky->value)
        ->where('workspace_id', $workspace->id)
        ->get();

    // Both HTTP calls (refreshSession + createSession fallback) are metered
    expect($events)->toHaveCount(2)
        ->and($events->where('succeeded', false)->count())->toBe(1)
        ->and($events->where('succeeded', true)->count())->toBe(1);
});

it('records a token_refresh event for each network call when both refreshSession and createSession fail', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = makeBlueskyAppPasswordAccount($workspace);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([], 400),
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired'], 401),
    ]);

    app(TokenManager::class)->fresh($account);

    $events = UsageEvent::where('operation', 'token_refresh')
        ->where('platform', Platform::Bluesky->value)
        ->where('workspace_id', $workspace->id)
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->where('succeeded', false)->count())->toBe(2);
});

it('does not record a token_refresh event when no network call is made (empty refreshJwt skipped)', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();

    // No refreshJwt means refreshBlueskySession bails early (no HTTP); createSession gets called
    $account = makeBlueskyAppPasswordAccount($workspace, refreshJwt: '');

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'accessJwt' => 'login-jwt',
            'refreshJwt' => 'login-rjwt',
        ]),
    ]);

    app(TokenManager::class)->fresh($account);

    $events = UsageEvent::where('operation', 'token_refresh')
        ->where('platform', Platform::Bluesky->value)
        ->where('workspace_id', $workspace->id)
        ->get();

    // Only the createSession call is metered; refreshSession was skipped (no network)
    expect($events)->toHaveCount(1)
        ->and($events->first()->succeeded)->toBeTrue();
});

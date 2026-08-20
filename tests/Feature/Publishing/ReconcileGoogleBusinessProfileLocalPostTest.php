<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\ReconcileGoogleBusinessProfileLocalPost;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\GoogleBusinessProfileConnector;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;

function googleBusinessProfileTarget(string $state = 'PROCESSING'): PostTarget
{
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'google-token',
    ]);

    return PostTarget::factory()->for($post)->published()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::GoogleBusinessProfile,
        'remote_id' => 'accounts/one/locations/one/localPosts/post-one',
        'remote_metadata' => [
            'name' => 'accounts/one/locations/one/localPosts/post-one',
            'state' => $state,
        ],
    ]);
}

test('reconciliation preserves the Google lifecycle state', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-one',
            'state' => 'LIVE',
            'searchUrl' => 'https://business.google.com/post-one',
        ]),
    ]);
    $target = googleBusinessProfileTarget();

    (new ReconcileGoogleBusinessProfileLocalPost($target))->handle(
        app(GoogleBusinessProfileConnector::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_metadata['state'])->toBe('LIVE')
        ->and($target->remote_metadata['search_url'])->toBe('https://business.google.com/post-one');
});

test('reconciliation marks a rejected Local Post as a validation failure', function () {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-one',
            'state' => 'REJECTED',
        ]),
    ]);
    $target = googleBusinessProfileTarget();

    (new ReconcileGoogleBusinessProfileLocalPost($target))->handle(
        app(GoogleBusinessProfileConnector::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind->value)->toBe('validation');
});

test('reconciliation releases the current unique job while Google is still processing', function () {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-one',
            'state' => 'PROCESSING',
        ]),
    ]);
    $target = googleBusinessProfileTarget();
    $job = new ReconcileGoogleBusinessProfileLocalPost($target);
    $job->withFakeQueueInteractions();

    $job->handle(
        app(GoogleBusinessProfileConnector::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
    );

    $job->assertReleased(30);
    expect($target->refresh()->remote_metadata['reconcile_polls'])->toBe(1);
});

test('reconciliation force-refreshes once after a lifecycle unauthorized response', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 7200,
        ]),
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one' => Http::sequence()
            ->push([], 401)
            ->push([
                'name' => 'accounts/one/locations/one/localPosts/post-one',
                'state' => 'LIVE',
            ]),
    ]);
    $target = googleBusinessProfileTarget();
    $account = $target->account()->firstOrFail();
    $account->secret()->firstOrFail()->forceFill(['refresh_token' => 'refresh-old'])->save();

    (new ReconcileGoogleBusinessProfileLocalPost($target))->handle(
        app(GoogleBusinessProfileConnector::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
    );

    expect($target->refresh()->remote_metadata['state'])->toBe('LIVE')
        ->and($account->refresh()->status)->toBe(ConnectedAccountStatus::Active);
    Http::assertSentCount(3);
});

test('reconciliation marks the account needs attention when lifecycle authorization remains revoked', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 7200,
        ]),
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one' => Http::sequence()
            ->push([], 401)
            ->push([], 401),
    ]);
    $target = googleBusinessProfileTarget();
    $account = $target->account()->firstOrFail();
    $account->secret()->firstOrFail()->forceFill(['refresh_token' => 'refresh-old'])->save();

    (new ReconcileGoogleBusinessProfileLocalPost($target))->handle(
        app(GoogleBusinessProfileConnector::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->error_kind->value)->toBe('auth_expired')
        ->and($account->refresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention);
    Http::assertSentCount(3);
});

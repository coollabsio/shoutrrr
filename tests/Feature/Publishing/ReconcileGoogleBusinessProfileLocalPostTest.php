<?php

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

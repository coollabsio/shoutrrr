<?php

// tests/Feature/Engagement/FetchPostTargetRepliesTest.php
use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyFetchResult;
use App\Enums\Platform;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Carbon\CarbonImmutable;

function targetWithPost(): PostTarget
{
    $post = Post::factory()->create();

    // Give the account valid, non-expiring credentials so the real TokenManager
    // returns the stored token without making a live OAuth refresh call.
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://root',
        'remote_ids' => ['at://root'],
    ]);
}

function fakeFetch(array $replies): void
{
    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')->andReturn(ReplyFetchResult::ok($replies));

    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);

    app()->instance(EngagementConnectorRegistry::class, $registry);
}

test('the job inserts fetched replies with the workspace id', function () {
    $target = targetWithPost();

    fakeFetch([
        new FetchedReply('at://r1', 'c1', 'at://root', 'fan', 'Fan', null, 'nice', CarbonImmutable::now()),
    ]);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    $reply = PostTargetReply::withoutGlobalScopes()->first();
    expect($reply->remote_reply_id)->toBe('at://r1');
    expect($reply->workspace_id)->toBe($target->post->workspace_id);
    expect($target->fresh()->reply_fetched_at)->not->toBeNull();
});

test('re-running the job does not duplicate replies', function () {
    $target = targetWithPost();
    $replies = [new FetchedReply('at://r1', 'c1', 'at://root', 'fan', 'Fan', null, 'nice', CarbonImmutable::now())];

    fakeFetch($replies);
    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    fakeFetch($replies);
    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    expect(PostTargetReply::withoutGlobalScopes()->count())->toBe(1);
});

test('a failed fetch does not stamp reply_fetched_at', function () {
    $target = targetWithPost();

    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')->andReturn(ReplyFetchResult::failed('boom'));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    expect($target->fresh()->reply_fetched_at)->toBeNull();
});

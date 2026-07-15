<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchAccountReplies;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Engagement\ReplyFetchCadence;
use App\Services\Engagement\ReplyPersister;
use App\Services\Publishing\TokenManager;

function runAccountFetch(ConnectedAccount $account): void
{
    (new FetchAccountReplies($account))->handle(
        app(EngagementConnectorRegistry::class),
        app(TokenManager::class),
        app(ReplyPersister::class),
        app(ReplyFetchCadence::class),
    );
}

function xBatchAccountWithToken(): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'handle' => '@owner',
        'status' => ConnectedAccountStatus::Active,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tok']);

    return $account;
}

function xTargetFor(ConnectedAccount $account, string $remoteId): PostTarget
{
    return PostTarget::factory()->for(Post::factory())->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => $remoteId,
        'remote_ids' => [$remoteId],
        'posted_at' => now()->subHours(2),
    ]);
}

test('it batches an account\'s due X targets into one call and routes replies back', function () {
    $account = xBatchAccountWithToken();
    $t500 = xTargetFor($account, '500');
    $t501 = xTargetFor($account, '501');

    Http::fake(['api.twitter.com/2/tweets/search/recent*' => Http::response([
        'data' => [
            ['id' => '900', 'text' => 'a', 'author_id' => '1', 'created_at' => '2026-07-14T10:00:00.000Z', 'conversation_id' => '500'],
            ['id' => '901', 'text' => 'b', 'author_id' => '1', 'created_at' => '2026-07-14T10:01:00.000Z', 'conversation_id' => '501'],
        ],
        'includes' => ['users' => [['id' => '1', 'username' => 'fan', 'name' => 'Fan']]],
    ])]);

    runAccountFetch($account);

    Http::assertSentCount(1);
    expect(PostTargetReply::withoutGlobalScopes()->where('post_target_id', $t500->id)->pluck('remote_reply_id')->all())->toBe(['900']);
    expect(PostTargetReply::withoutGlobalScopes()->where('post_target_id', $t501->id)->pluck('remote_reply_id')->all())->toBe(['901']);
    expect($t500->fresh()->reply_fetched_at)->not->toBeNull();
});

test('a rate-limited batch parks the account for the retry-after window', function () {
    $this->freezeTime();
    $account = xBatchAccountWithToken();
    xTargetFor($account, '500');

    Http::fake(['api.twitter.com/2/tweets/search/recent*' => Http::response(['title' => 'Too Many Requests'], 429, ['Retry-After' => '90'])]);

    runAccountFetch($account);

    $fresh = ConnectedAccount::withoutGlobalScopes()->find($account->id);
    expect($fresh->engagement_rate_limited_until->timestamp)->toBe(now()->addSeconds(90)->timestamp);
});

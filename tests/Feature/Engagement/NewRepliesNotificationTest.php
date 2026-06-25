<?php

// tests/Feature/Engagement/NewRepliesNotificationTest.php
use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyFetchResult;
use App\Enums\Platform;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Notifications\NewRepliesNotification;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

function fetchJobWith(array $replies, User $author): PostTarget
{
    $post = Post::factory()->for($author, 'author')->create();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://root',
    ]);

    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')->andReturn(ReplyFetchResult::ok($replies));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    return $target;
}

test('one batched notification fires when new replies land', function () {
    Notification::fake();
    $author = User::factory()->create();

    $target = fetchJobWith([
        new FetchedReply('at://r1', 'c1', 'at://root', 'a', 'A', null, 'hi', CarbonImmutable::now()),
        new FetchedReply('at://r2', 'c2', 'at://root', 'b', 'B', null, 'yo', CarbonImmutable::now()),
    ], $author);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    Notification::assertSentToTimes($author, NewRepliesNotification::class, 1);
});

test('no notification fires when nothing new', function () {
    Notification::fake();
    $author = User::factory()->create();
    $target = fetchJobWith([], $author);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    Notification::assertNothingSent();
});

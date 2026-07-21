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
use App\Services\Engagement\ReplyPersister;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    $reply = PostTargetReply::withoutGlobalScopes()->first();
    expect($reply->remote_reply_id)->toBe('at://r1');
    expect($reply->workspace_id)->toBe($target->post->workspace_id);
    expect($target->fresh()->reply_fetched_at)->not->toBeNull();
});

test('the job does not fetch replies for a disabled account', function () {
    $target = targetWithPost();
    $target->account->forceFill(['disabled_at' => now()])->save();

    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldNotReceive('for');

    (new FetchPostTargetReplies($target))->handle($registry, app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect($target->fresh()->reply_fetched_at)->toBeNull();
});

test('re-running the job does not duplicate replies', function () {
    $target = targetWithPost();
    $replies = [new FetchedReply('at://r1', 'c1', 'at://root', 'fan', 'Fan', null, 'nice', CarbonImmutable::now())];

    fakeFetch($replies);
    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    fakeFetch($replies);
    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect(PostTargetReply::withoutGlobalScopes()->count())->toBe(1);
});

test('the job resolves credentials for threads instead of passing an empty token', function () {
    // Regression: the reply-fetch job gated credential resolution to
    // X/Bluesky/LinkedIn, so Threads (and Facebook/Instagram) reached their
    // Graph connectors with `[]` and authenticated with an empty token.
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'token_expires_at' => now()->addDays(30),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'threads-token',
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Threads,
        'remote_id' => 'th-root',
        'remote_ids' => ['th-root'],
    ]);

    $captured = null;
    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')
        ->andReturnUsing(function ($account, $target, $credentials) use (&$captured) {
            $captured = $credentials;

            return ReplyFetchResult::ok([]);
        });
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect($captured)->toBe(['access_token' => 'threads-token']);
});

test('an empty fetch increments the empty streak; a non-empty fetch resets it', function () {
    $target = targetWithPost();
    $target->forceFill(['reply_fetch_empty_streak' => 2])->save();

    fakeFetch([]);
    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));
    expect($target->fresh()->reply_fetch_empty_streak)->toBe(3);

    fakeFetch([
        new FetchedReply('at://r1', 'c1', 'at://root', 'fan', 'Fan', null, 'nice', CarbonImmutable::now()),
    ]);
    (new FetchPostTargetReplies($target->fresh()))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));
    expect($target->fresh()->reply_fetch_empty_streak)->toBe(0);
});

test('the fetch outcome is logged for fleet visibility', function () {
    Log::spy();
    $target = targetWithPost();

    fakeFetch([
        new FetchedReply('at://r1', 'c1', 'at://root', 'fan', 'Fan', null, 'nice', CarbonImmutable::now()),
    ]);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'engagement.fetch'
            && $context['outcome'] === 'ok'
            && $context['inserted'] === 1)
        ->once();
});

test('a rate-limited fetch parks the account and does not stamp reply_fetched_at', function () {
    $this->freezeTime();
    $target = targetWithPost();

    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')->andReturn(ReplyFetchResult::rateLimited('slow down', 120));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect($target->fresh()->reply_fetched_at)->toBeNull();
    $account = $target->account()->withoutGlobalScopes()->first();
    expect($account->engagement_rate_limited_until->timestamp)->toBe(now()->addSeconds(120)->timestamp);
});

test('a failed fetch does not stamp reply_fetched_at', function () {
    $target = targetWithPost();

    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')->andReturn(ReplyFetchResult::failed('boom'));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect($target->fresh()->reply_fetched_at)->toBeNull();
});

test('the job stores the base conversation id when fetched replies are out of order', function (): void {
    $target = targetWithPost();

    fakeFetch([
        new FetchedReply('at://child', 'c2', 'at://base', 'fan', 'Fan', null, 'child', CarbonImmutable::now()),
        new FetchedReply('at://base', 'c1', 'at://root', 'fan', 'Fan', null, 'base', CarbonImmutable::now()->subMinute()),
    ]);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    $child = PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'at://child')->firstOrFail();

    expect($child->conversation_remote_id)->toBe('at://base');
});

function linkedInTargetWithCapability(?bool $capable): PostTarget
{
    $post = Post::factory()->create();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn,
        'token_expires_at' => now()->addHour(),
        'capabilities' => $capable === null ? null : ['linkedin_engagement' => $capable],
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'li-token',
    ]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn,
        'remote_id' => 'urn:li:share:1',
        'remote_ids' => ['urn:li:share:1'],
    ]);
}

test('linkedin reply fetch is skipped for an account without the engagement capability', function () {
    $target = linkedInTargetWithCapability(null);

    // The connector must never be reached — we know the call would 403.
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldNotReceive('for');

    (new FetchPostTargetReplies($target))->handle($registry, app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect($target->fresh()->reply_fetched_at)->toBeNull();
});

test('a linkedin unsupported fetch disables the account engagement capability', function () {
    $target = linkedInTargetWithCapability(true);

    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('fetchReplies')->andReturn(ReplyFetchResult::unsupported('no access'));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    (new FetchPostTargetReplies($target))->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class), app(ReplyPersister::class), app(InstanceSettings::class));

    expect($target->account->fresh()->capabilities['linkedin_engagement'])->toBeFalse();
});

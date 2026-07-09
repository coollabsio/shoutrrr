<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;

/**
 * Same factory chain as PublishPostTargetTest's `publishTarget()` helper
 * (workspace via ConnectedAccount::factory() -> Post::factory() -> PostTarget::factory()),
 * generalized to accept a platform so a frozen-X + available-Bluesky sibling pair can be built.
 */
function makePendingTarget(string $platform, ?Post $post = null): PostTarget
{
    $post ??= Post::factory()->create(['status' => PostStatus::Publishing]);
    $account = ConnectedAccount::factory()->create([
        'platform' => $platform,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => $platform,
        'sections' => ['hello'],
        'status' => 'pending',
    ]);
}

function bindSucceedingConnector(): void
{
    $connector = new class implements PublishConnector
    {
        public function publish(PublishContext $context): PublishResult
        {
            return PublishResult::success(['remote-id']);
        }

        public function delete(PostTarget $target, array $credentials): void {}
    };

    app()->instance(PublishConnectorRegistry::class, new class($connector) extends PublishConnectorRegistry
    {
        public function __construct(private PublishConnector $connector) {}

        public function for(Platform $platform): PublishConnector
        {
            return $this->connector;
        }
    });
}

function runPublish(PostTarget $target): void
{
    app(PublishPostTarget::class, ['target' => $target])->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );
}

it('skips a target whose platform is frozen instead of publishing', function () {
    $target = makePendingTarget('x');

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    runPublish($target);

    expect($target->fresh()->status)->toBe(PostTargetStatus::Skipped);
    expect($target->fresh()->attemptLogs()->count())->toBe(0);
});

it('leaves a sibling target on an available platform unaffected and rolls the post up to Partial', function () {
    $post = Post::factory()->create(['status' => PostStatus::Publishing]);
    $xTarget = makePendingTarget('x', $post);
    $blueskyTarget = makePendingTarget('bluesky', $post);

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);
    bindSucceedingConnector();

    runPublish($xTarget);
    runPublish($blueskyTarget);

    expect($xTarget->fresh()->status)->toBe(PostTargetStatus::Skipped);
    expect($xTarget->fresh()->attemptLogs()->count())->toBe(0);
    expect($blueskyTarget->fresh()->status)->toBe(PostTargetStatus::Published);
    expect($post->fresh()->status)->toBe(PostStatus::Partial);
});

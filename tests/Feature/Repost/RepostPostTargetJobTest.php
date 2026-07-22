<?php

use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Jobs\RepostPostTarget;
use App\Services\Publishing\TokenManager;
use App\Services\Repost\Contracts\RepostConnector;
use App\Services\Repost\RepostConnectorRegistry;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Date;

/**
 * @param  array<string, mixed>  $postAttributes
 */
function eligibleRepostTarget(array $postAttributes = []): PostTarget
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'remote_account_id' => 'U',
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);
    $post = Post::factory()->create([...['auto_repost' => true], ...$postAttributes]);

    return PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'TWEET1',
        'posted_at' => Date::now()->subHours(200),
        'reposted_at' => null,
    ]);
}

beforeEach(function (): void {
    // Stub token refresh so no real credential resolution happens.
    $this->mock(TokenManager::class, fn ($mock) => $mock->shouldReceive('fresh')->andReturn(['access_token' => 'tok']));
});

test('job reposts an eligible target and records the remote id', function (): void {
    $target = eligibleRepostTarget();

    $connector = Mockery::mock(RepostConnector::class);
    $connector->shouldReceive('repost')->once()->andReturn(PublishResult::success(['TWEET1']));
    $this->mock(RepostConnectorRegistry::class, fn ($m) => $m->shouldReceive('for')->andReturn($connector));

    app()->call([new RepostPostTarget($target), 'handle']);

    $target->refresh();
    expect($target->reposted_at)->not->toBeNull()
        ->and($target->repost_remote_id)->toBe('TWEET1');
});

test('job never double-boosts an already-reposted target', function (): void {
    $target = eligibleRepostTarget();
    $target->forceFill(['reposted_at' => Date::now()])->save();

    $this->mock(RepostConnectorRegistry::class, fn ($m) => $m->shouldReceive('for')->never());

    app()->call([new RepostPostTarget($target), 'handle']);

    expect(true)->toBeTrue(); // connector never called (asserted by ->never())
});

test('a hard failure marks the target reposted with no remote id, bounding retries', function (): void {
    $target = eligibleRepostTarget();

    $connector = Mockery::mock(RepostConnector::class);
    $connector->shouldReceive('repost')->once()->andReturn(PublishResult::failure(ErrorKind::Validation, 'duplicate'));
    $this->mock(RepostConnectorRegistry::class, fn ($m) => $m->shouldReceive('for')->andReturn($connector));

    app()->call([new RepostPostTarget($target), 'handle']);

    $target->refresh();
    expect($target->reposted_at)->not->toBeNull()
        ->and($target->repost_remote_id)->toBeNull();
});

test('a platform frozen instance-wide blocks the repost and leaves reposted_at null for the next tick', function (): void {
    $target = eligibleRepostTarget();

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    $this->mock(RepostConnectorRegistry::class, fn ($m) => $m->shouldReceive('for')->never());

    app()->call([new RepostPostTarget($target), 'handle']);

    $target->refresh();
    expect($target->reposted_at)->toBeNull();
});

test('a workspace that cannot publish blocks the repost and leaves reposted_at null for the next tick', function (): void {
    config(['subscriptions.enabled' => true]);

    // The first workspace created is "initial" and always billing-exempt, so seed
    // one before the workspace under test to force a real subscription check.
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();

    $target = eligibleRepostTarget(['workspace_id' => $workspace->id]);

    $this->mock(RepostConnectorRegistry::class, fn ($m) => $m->shouldReceive('for')->never());

    app()->call([new RepostPostTarget($target), 'handle']);

    $target->refresh();
    expect($target->reposted_at)->toBeNull();
});

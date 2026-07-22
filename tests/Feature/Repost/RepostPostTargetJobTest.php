<?php

use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Jobs\RepostPostTarget;
use App\Services\Publishing\TokenManager;
use App\Services\Repost\Contracts\RepostConnector;
use App\Services\Repost\RepostConnectorRegistry;
use Illuminate\Support\Facades\Date;

function eligibleRepostTarget(): PostTarget
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'remote_account_id' => 'U',
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);
    $post = Post::factory()->create(['auto_repost' => true]);

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

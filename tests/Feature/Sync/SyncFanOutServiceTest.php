<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\Posts\DraftService;
use App\Services\Sync\SyncFanOutService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A composer post already published to $source, plus a pipeline source->[dests].
 *
 * @param  list<ConnectedAccount>  $destinations
 * @return array{0: Post, 1: PostTarget}
 */
function publishedSourceWithPipeline(ConnectedAccount $source, array $destinations, string $workspaceId): array
{
    $pipeline = SyncPipeline::factory()->create([
        'workspace_id' => $workspaceId,
        'source_connected_account_id' => $source->id,
        'enabled' => true,
    ]);
    $pipeline->destinations()->attach(collect($destinations)->pluck('id')->all());

    $post = Post::factory()->create([
        'workspace_id' => $workspaceId,
        'origin' => PostOrigin::Composer->value,
        'status' => PostStatus::Published->value,
        'segments' => ['Hello world from the source platform'],
        'base_text' => 'Hello world from the source platform',
    ]);
    $target = PostTarget::create([
        'post_id' => $post->id,
        'connected_account_id' => $source->id,
        'platform' => $source->platform->value,
        'sections' => ['Hello world from the source platform'],
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'src-123',
    ]);

    return [$post, $target];
}

beforeEach(function () {
    Queue::fake();
    config(['sync.enabled' => true]);
});

test('fan-out creates one synced post with a target per destination and recomputed sections', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    $synced = Post::where('source_post_id', $post->id)->first();
    expect($synced)->not->toBeNull()
        ->and($synced->origin)->toBe(PostOrigin::Sync)
        ->and($synced->status)->toBe(PostStatus::Publishing)
        ->and($synced->targets)->toHaveCount(1)
        ->and($synced->targets->first()->connected_account_id)->toBe($dest->id)
        ->and($synced->targets->first()->sections)->not->toBeEmpty();
    Queue::assertPushed(PublishPostTarget::class);
});

test('fan-out excludes destinations already targeted by the source post', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    PostTarget::create([
        'post_id' => $post->id,
        'connected_account_id' => $dest->id,
        'platform' => $dest->platform->value,
        'sections' => ['Hello'],
        'status' => PostTargetStatus::Published->value,
    ]);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('fan-out is idempotent under repeated calls', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);
    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(1);
});

test('a synced post never triggers further fan-out', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $loopPipeline = SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $dest->id,
        'enabled' => true,
    ]);
    $loopPipeline->destinations()->attach($source->id);

    $syncedPost = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'origin' => PostOrigin::Sync->value,
    ]);
    $syncedTarget = PostTarget::create([
        'post_id' => $syncedPost->id,
        'connected_account_id' => $dest->id,
        'platform' => $dest->platform->value,
        'sections' => ['x'],
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'dst-1',
    ]);

    app(SyncFanOutService::class)->fanOut($syncedTarget);

    expect(Post::where('origin', PostOrigin::Sync->value)->where('source_post_id', $syncedPost->id)->count())->toBe(0);
});

test('skip_sync suppresses fan-out', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);
    $post->update(['skip_sync' => true]);

    app(SyncFanOutService::class)->fanOut($target->fresh());

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('fan-out is inert when sync.enabled is false', function () {
    config(['sync.enabled' => false]);
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

test('copied media files are cleaned up when a later fan-out step fails', function () {
    Storage::fake('public');
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    $mediaPath = 'media/source.jpg';
    Storage::disk('public')->put($mediaPath, 'jpeg-bytes');
    PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'post_id' => $post->id,
        'disk' => 'public',
        'path' => $mediaPath,
    ]);

    // Blow up after copyMediaInto has already written the copied file.
    $this->mock(DraftService::class, function ($mock) {
        $mock->shouldReceive('syncTargets')->andThrow(new RuntimeException('boom'));
    });

    expect(fn () => app(SyncFanOutService::class)->fanOut($target))
        ->toThrow(RuntimeException::class);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([$mediaPath]);
});

test('disabled destination accounts are excluded', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->disabled()->create(['workspace_id' => $workspace->id]);
    [$post, $target] = publishedSourceWithPipeline($source, [$dest], $workspace->id);

    app(SyncFanOutService::class)->fanOut($target);

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

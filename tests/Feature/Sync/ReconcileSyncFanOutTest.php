<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('reconcile creates a missing synced child for a recently published source target', function () {
    config(['sync.enabled' => true, 'sync.reconcile_lookback_minutes' => 60]);
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'source_connected_account_id' => $source->id]);
    $pipeline->destinations()->attach($dest->id);

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'origin' => PostOrigin::Composer->value, 'status' => PostStatus::Published->value, 'segments' => ['hi']]);
    PostTarget::create([
        'post_id' => $post->id, 'connected_account_id' => $source->id, 'platform' => Platform::X->value,
        'sections' => ['hi'], 'status' => PostTargetStatus::Published->value, 'remote_id' => 'r1', 'posted_at' => Date::now(),
    ]);

    $this->artisan('sync:reconcile')->assertSuccessful();

    expect(Post::where('source_post_id', $post->id)->count())->toBe(1);
});

test('reconcile ignores targets published outside the lookback window', function () {
    config(['sync.enabled' => true, 'sync.reconcile_lookback_minutes' => 60]);
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $dest = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id, 'source_connected_account_id' => $source->id]);
    $pipeline->destinations()->attach($dest->id);

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Published->value, 'segments' => ['hi']]);
    PostTarget::create([
        'post_id' => $post->id, 'connected_account_id' => $source->id, 'platform' => Platform::X->value,
        'sections' => ['hi'], 'status' => PostTargetStatus::Published->value, 'remote_id' => 'r1', 'posted_at' => Date::now()->subHours(3),
    ]);

    $this->artisan('sync:reconcile')->assertSuccessful();

    expect(Post::where('source_post_id', $post->id)->count())->toBe(0);
});

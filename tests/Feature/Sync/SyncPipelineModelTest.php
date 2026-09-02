<?php

declare(strict_types=1);

use App\Enums\PostOrigin;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\SyncPipeline;
use Illuminate\Database\UniqueConstraintViolationException;

test('a post defaults to composer origin', function () {
    $post = Post::factory()->create();

    expect($post->origin)->toBe(PostOrigin::Composer)
        ->and($post->skip_sync)->toBeFalse();
});

test('a sync pipeline has a source and destinations and cascades on delete', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $destA = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $destB = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $pipeline = SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $source->id,
    ]);
    $pipeline->destinations()->attach([$destA->id, $destB->id]);

    expect($pipeline->source->id)->toBe($source->id)
        ->and($pipeline->destinations->pluck('id')->all())->toEqualCanonicalizing([$destA->id, $destB->id]);

    $pipeline->delete();
    $this->assertDatabaseMissing('sync_pipeline_destinations', ['sync_pipeline_id' => $pipeline->id]);
});

test('two synced posts cannot share the same source post and pipeline', function () {
    [, $workspace] = ownerActingIn();
    $source = Post::factory()->create(['workspace_id' => $workspace->id]);
    $pipeline = SyncPipeline::factory()->create(['workspace_id' => $workspace->id]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'origin' => PostOrigin::Sync->value,
        'source_post_id' => $source->id,
        'sync_pipeline_id' => $pipeline->id,
    ]);

    expect(fn () => Post::factory()->create([
        'workspace_id' => $workspace->id,
        'origin' => PostOrigin::Sync->value,
        'source_post_id' => $source->id,
        'sync_pipeline_id' => $pipeline->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

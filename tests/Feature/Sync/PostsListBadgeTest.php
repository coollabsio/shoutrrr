<?php

declare(strict_types=1);

use App\Enums\PostOrigin;
use App\Models\Post;
use App\Support\PostListItem;

test('the post list item exposes origin and source post', function () {
    [, $workspace] = ownerActingIn();
    $source = Post::factory()->create(['workspace_id' => $workspace->id]);
    $synced = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'origin' => PostOrigin::Sync->value,
        'source_post_id' => $source->id,
    ]);

    $item = PostListItem::make($synced->load(['author', 'targets', 'media']));

    expect($item['origin'])->toBe('sync')
        ->and($item['source_post_id'])->toBe($source->id);
});

test('a composer post reports composer origin and no source', function () {
    [, $workspace] = ownerActingIn();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    $item = PostListItem::make($post->load(['author', 'targets', 'media']));

    expect($item['origin'])->toBe('composer')
        ->and($item['source_post_id'])->toBeNull();
});

test('the post list item reports external origin', function () {
    [, $workspace] = ownerActingIn();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'origin' => PostOrigin::External->value]);
    $item = PostListItem::make($post->load(['author', 'targets', 'media']));
    expect($item['origin'])->toBe('external');
});

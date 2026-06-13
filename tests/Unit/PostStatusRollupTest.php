<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PostStatusRollup;

function rollupPost(array $statuses): Post
{
    $post = Post::factory()->create(['status' => PostStatus::Publishing, 'published_at' => null]);

    foreach ($statuses as $status) {
        PostTarget::factory()->for($post)->create(['status' => $status->value]);
    }

    return $post;
}

test('all published rolls up to Published and sets published_at', function () {
    $post = rollupPost([PostTargetStatus::Published, PostTargetStatus::Published]);

    app(PostStatusRollup::class)->recompute($post);

    expect($post->refresh()->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull();
});

test('any still-pending rolls up to Publishing', function () {
    $post = rollupPost([PostTargetStatus::Published, PostTargetStatus::Pending]);

    app(PostStatusRollup::class)->recompute($post);

    expect($post->refresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->published_at)->toBeNull();
});

test('mixed terminal rolls up to Partial and sets published_at', function () {
    $post = rollupPost([PostTargetStatus::Published, PostTargetStatus::Failed]);

    app(PostStatusRollup::class)->recompute($post);

    expect($post->refresh()->status)->toBe(PostStatus::Partial)
        ->and($post->published_at)->not->toBeNull();
});

test('all failed rolls up to Failed', function () {
    $post = rollupPost([PostTargetStatus::Failed, PostTargetStatus::Failed]);

    app(PostStatusRollup::class)->recompute($post);

    expect($post->refresh()->status)->toBe(PostStatus::Failed)
        ->and($post->published_at)->toBeNull();
});

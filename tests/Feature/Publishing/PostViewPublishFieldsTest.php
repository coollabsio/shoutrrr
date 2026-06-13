<?php

use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\PostView;

test('post view exposes per-target publish status and root published_at', function () {
    $post = Post::factory()->create(['status' => PostStatus::Partial, 'published_at' => now()]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Failed->value,
        'error_kind' => ErrorKind::RateLimited->value,
        'error_message' => 'slow down',
        'remote_id' => 'abc',
    ]);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['published_at'])->not->toBeNull()
        ->and($view['targets'][0]['status'])->toBe('failed')
        ->and($view['targets'][0]['error_kind'])->toBe('rate_limited')
        ->and($view['targets'][0]['error_message'])->toBe('slow down')
        ->and($view['targets'][0]['remote_id'])->toBe('abc');
});

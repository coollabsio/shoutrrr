<?php

use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Date;

test('post targets persist repost state and posts persist the override', function (): void {
    $target = PostTarget::factory()->create([
        'reposted_at' => Date::now(),
        'repost_remote_id' => 'urn:li:share:123',
    ]);

    $post = Post::factory()->create(['auto_repost' => true]);

    expect($target->fresh()->repost_remote_id)->toBe('urn:li:share:123')
        ->and($target->fresh()->reposted_at)->not->toBeNull()
        ->and($post->fresh()->auto_repost)->toBeTrue();
});

test('post auto_repost defaults to null (inherit)', function (): void {
    expect(Post::factory()->create()->fresh()->auto_repost)->toBeNull();
});

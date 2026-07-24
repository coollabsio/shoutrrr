<?php

use App\Models\PostTarget;
use App\Services\Repost\EngagementScore;

test('engagement score weights comments and reposts double', function (): void {
    $target = PostTarget::factory()->make(['likes' => 10, 'comments' => 3, 'reposts' => 2]);

    expect((new EngagementScore)->for($target))->toBe(10 + 2 * (3 + 2));
});

test('engagement score treats null metrics as zero', function (): void {
    $target = PostTarget::factory()->make(['likes' => null, 'comments' => null, 'reposts' => null]);

    expect((new EngagementScore)->for($target))->toBe(0);
});

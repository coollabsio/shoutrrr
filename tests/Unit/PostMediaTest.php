<?php

use App\Models\PostMedia;

test('isVideo reflects the kind column', function (): void {
    expect(PostMedia::factory()->make(['kind' => 'video'])->isVideo())->toBeTrue();
    expect(PostMedia::factory()->make(['kind' => 'image'])->isVideo())->toBeFalse();
});

test('video factory state sets a video mime and duration', function (): void {
    $media = PostMedia::factory()->video()->make();

    expect($media->kind)->toBe('video')
        ->and($media->mime)->toBe('video/mp4')
        ->and($media->duration_seconds)->toBeGreaterThan(0);
});

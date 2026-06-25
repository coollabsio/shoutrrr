<?php

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

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

test('toView exposes edit settings and source url for a beautified image', function () {
    Storage::fake('public');

    $media = PostMedia::factory()->create([
        'source_disk' => 'public',
        'source_path' => 'media/ws/source.png',
        'edit_settings' => ['version' => 1, 'padding' => 64],
    ]);

    $view = $media->toView();

    expect($view['edit_settings'])->toBe(['version' => 1, 'padding' => 64])
        ->and($view['source_url'])->toContain('media/ws/source.png')
        ->and($view['id'])->toBe($media->id)
        ->and($view)->toHaveKeys(['url', 'mime', 'kind', 'duration_seconds', 'alt_text', 'position']);
});

test('toView returns null edit settings and source url for a plain image', function () {
    $media = PostMedia::factory()->create();

    expect($media->toView()['edit_settings'])->toBeNull()
        ->and($media->toView()['source_url'])->toBeNull();
});

test('a new media instance defaults to the image kind in memory', function () {
    expect((new PostMedia)->kind)->toBe('image');
});

test('deleting a media removes its composed and retained source files', function () {
    Storage::fake('public');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/composed.png',
        'source_disk' => 'public',
        'source_path' => 'media/ws/source.png',
    ]);
    Storage::disk('public')->put('media/ws/composed.png', 'composed');
    Storage::disk('public')->put('media/ws/source.png', 'source');

    $media->delete();

    Storage::disk('public')->assertMissing('media/ws/composed.png');
    Storage::disk('public')->assertMissing('media/ws/source.png');
});

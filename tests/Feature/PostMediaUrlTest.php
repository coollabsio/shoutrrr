<?php

use App\Models\PostMedia;

it('signs the url for media on a private disk so the local serve route does not 403', function () {
    $media = PostMedia::factory()->video()->create([
        'disk' => 'local',
        'path' => 'media/ws/clip.mp4',
    ]);

    // The private local-serve route (and a private S3 bucket) reject an
    // unsigned GET with 403; the URL must be signed/temporary to load.
    expect($media->url())->toContain('signature=');
});

it('returns a plain unsigned url for media on the public disk', function () {
    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.jpg',
    ]);

    expect($media->url())->not->toContain('signature=');
});

it('signs the source_url for a beautified source on a private disk', function () {
    $media = PostMedia::factory()->create([
        'disk' => 'local',
        'path' => 'media/ws/composed.png',
        'source_disk' => 'local',
        'source_path' => 'media/ws/source.png',
    ]);

    expect($media->source_url())->toContain('signature=');
});

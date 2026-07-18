<?php

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Services\Media\PublicMediaUrl;
use Illuminate\Support\Facades\Storage;

it('returns a url containing the path for media on the public disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.jpg', 'contents');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.jpg',
    ]);

    $url = app(PublicMediaUrl::class)->for($media);

    expect($url)->toContain('media/ws/pic.jpg')
        ->and($url)->not->toContain('signature=');
});

it('returns an absolute url for public-disk media so Meta can fetch it server-side', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.jpg', 'contents');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.jpg',
    ]);

    $url = app(PublicMediaUrl::class)->for($media);

    // The `public` disk is configured host-relative ('url' => '/storage') so the
    // browser resolves it against the current origin. Meta fetches server-side and
    // has no origin, so a relative path is unfetchable.
    expect(parse_url($url, PHP_URL_SCHEME))->not->toBeNull()
        ->and(parse_url($url, PHP_URL_HOST))->not->toBeNull();
});

it('leaves an already-conforming jpeg untouched rather than deriving a copy', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.jpg',
        'mime' => 'image/jpeg',
    ]);

    $url = app(PublicMediaUrl::class)->for($media, Platform::Instagram);

    expect($url)->toContain('media/ws/pic.jpg')
        ->and($url)->not->toContain('/derived/');
});

it('does not run a video through the image converter', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/clip.mp4', 'mp4-bytes');

    $media = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/ws/clip.mp4',
    ]);

    $url = app(PublicMediaUrl::class)->for($media, Platform::Instagram);

    expect($url)->toContain('media/ws/clip.mp4')
        ->and($url)->not->toContain('/derived/');
});

it('converts a webp for Threads, which accepts jpeg and png only', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.webp', transparentPng());

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.webp',
        'mime' => 'image/webp',
    ]);

    $url = app(PublicMediaUrl::class)->for($media, Platform::Threads);

    expect($url)->toContain('/derived/')
        ->and($url)->toContain('.jpg');
});

it('signs the url for media on a private disk so Meta can fetch it over a real HTTPS endpoint', function () {
    $media = PostMedia::factory()->video()->create([
        'disk' => 'local',
        'path' => 'media/ws/clip.mp4',
    ]);

    $url = app(PublicMediaUrl::class)->for($media);

    expect($url)->toContain('media/ws/clip.mp4')
        ->and($url)->toContain('signature=');
});

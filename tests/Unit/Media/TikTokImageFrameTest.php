<?php

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Services\Media\DerivedMedia;
use App\Services\Media\ImageToJpegConverter;
use App\Services\Media\PublicMediaUrl;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: int, 1: int}
 */
function storedImageSize(string $path): array
{
    $info = getimagesizefromstring((string) Storage::disk('public')->get($path));

    return [$info[0], $info[1]];
}

function storedPicture(int $width, int $height, string $mime = 'image/jpeg', ?int $recordedWidth = null, ?int $recordedHeight = null): PostMedia
{
    Storage::disk('public')->put('media/ws/pic', transparentPng($width, $height));

    return PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic',
        'mime' => $mime,
        'width' => $recordedWidth ?? $width,
        'height' => $recordedHeight ?? $height,
    ]);
}

beforeEach(function () {
    Storage::fake('public');
});

it('hands TikTok a jpeg that already fits the 1080p frame as stored', function () {
    $media = storedPicture(1080, 1920);

    $url = app(PublicMediaUrl::class)->for($media, Platform::TikTok);

    expect($url)->toEndWith('/storage/media/ws/pic')
        ->and($url)->not->toContain('/derived/');
});

it('downscales a landscape photo into the frame for TikTok', function () {
    $media = storedPicture(3000, 2000);

    $url = app(PublicMediaUrl::class)->for($media, Platform::TikTok);
    $path = DerivedMedia::path($media, 'jpg', 'fit1920x1080');

    expect($url)->toContain($path);
    [$width, $height] = storedImageSize($path);
    expect($width)->toBeLessThanOrEqual(1920)
        ->and($height)->toBeLessThanOrEqual(1080)
        ->and($width / $height)->toEqualWithDelta(1.5, 0.01);
});

it('downscales a portrait photo into the frame for TikTok', function () {
    $media = storedPicture(1500, 3000);

    app(PublicMediaUrl::class)->for($media, Platform::TikTok);

    [$width, $height] = storedImageSize(DerivedMedia::path($media, 'jpg', 'fit1920x1080'));
    expect($width)->toBeLessThanOrEqual(1080)
        ->and($height)->toBeLessThanOrEqual(1920);
});

it('converts a small png without enlarging it', function () {
    $media = storedPicture(400, 300, 'image/png');

    app(PublicMediaUrl::class)->for($media, Platform::TikTok);

    expect(storedImageSize(DerivedMedia::path($media, 'jpg', 'fit1920x1080')))->toBe([400, 300]);
});

it('treats unknown dimensions as too large and fits the short edge both ways', function () {
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/ws/pic', 'mime' => 'image/jpeg', 'width' => null, 'height' => null]);
    Storage::disk('public')->put('media/ws/pic', transparentPng(2000, 2000));

    app(PublicMediaUrl::class)->for($media, Platform::TikTok);

    expect(storedImageSize(DerivedMedia::path($media, 'jpg', 'fit1920x1080')))->toBe([1080, 1080]);
});

it('keeps the plain jpeg rendition for platforms without a frame', function () {
    $media = storedPicture(3000, 2000, 'image/png');

    $converted = app(ImageToJpegConverter::class)->convert($media);

    expect($converted->path)->toBe(DerivedMedia::path($media, 'jpg'))
        ->and(storedImageSize($converted->path))->toBe([3000, 2000]);
});

it('lists the framed rendition among the derived paths to clean up', function () {
    $media = PostMedia::factory()->create();

    expect(DerivedMedia::pathsFor($media))->toContain(DerivedMedia::path($media, 'jpg', 'fit1920x1080'));
});

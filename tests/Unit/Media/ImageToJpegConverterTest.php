<?php

use App\Models\PostMedia;
use App\Services\Media\ImageConversionFailed;
use App\Services\Media\ImageToJpegConverter;
use Illuminate\Support\Facades\Storage;

function transparentPng(int $width = 4, int $height = 4): string
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function mimeOf(string $bytes): string
{
    return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
}

it('re-encodes a png to a real jpeg stored alongside the original', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.png', transparentPng());

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
        'mime' => 'image/png',
    ]);

    $converted = app(ImageToJpegConverter::class)->convert($media);

    expect($converted->disk)->toBe('public')
        ->and($converted->path)->toBe('media/'.$media->workspace_id.'/derived/'.$media->id.'.jpg');

    Storage::disk('public')->assertExists($converted->path);

    // The URL ending in .jpg is not enough — Meta reads the bytes, so assert the
    // stored file really is JPEG-encoded.
    expect(mimeOf((string) Storage::disk('public')->get($converted->path)))->toBe('image/jpeg');

    // The original is left untouched for the other destinations sharing this file.
    Storage::disk('public')->assertExists('media/ws/pic.png');
});

it('reuses an already-derived jpeg instead of re-encoding on a retry', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.png', transparentPng());

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
        'mime' => 'image/png',
    ]);

    $converter = app(ImageToJpegConverter::class);
    $first = $converter->convert($media);

    Storage::disk('public')->put($first->path, 'sentinel-bytes');

    $second = $converter->convert($media);

    expect($second->path)->toBe($first->path)
        ->and(Storage::disk('public')->get($second->path))->toBe('sentinel-bytes');
});

it('deletes the derived jpeg when the media row is removed', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.png', transparentPng());

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
        'mime' => 'image/png',
    ]);

    $derived = app(ImageToJpegConverter::class)->convert($media)->path;
    Storage::disk('public')->assertExists($derived);

    $media->delete();

    Storage::disk('public')->assertMissing($derived);
    Storage::disk('public')->assertMissing('media/ws/pic.png');
});

it('fails with an actionable error when the bytes cannot be decoded', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.png', 'not-an-image');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
        'mime' => 'image/png',
    ]);

    app(ImageToJpegConverter::class)->convert($media);
})->throws(ImageConversionFailed::class);

it('refuses to decode a canvas beyond the pixel ceiling', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/huge.png', transparentPng(50, 50));

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/huge.png',
        'mime' => 'image/png',
    ]);

    (new ImageToJpegConverter(maxPixels: 100))->convert($media);
})->throws(ImageConversionFailed::class);

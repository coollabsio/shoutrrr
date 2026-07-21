<?php

use App\Services\Media\ImageCompressor;

/**
 * Build a non-trivial JPEG so it does not compress to a near-zero size.
 */
function compressorJpeg(int $width = 1200, int $height = 1200): string
{
    $img = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 2) {
        for ($x = 0; $x < $width; $x += 2) {
            $color = imagecolorallocate($img, ($x * $y) % 256, ($x + $y) % 256, ($x ^ $y) % 256);
            imagesetpixel($img, $x, $y, $color);
        }
    }

    ob_start();
    imagejpeg($img, null, 100);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/**
 * Build a small, well-under-any-byte-cap WebP so "does it get converted" tests
 * aren't confused by the compressor's own size-driven re-encode path.
 */
function compressorWebp(): string
{
    $img = imagecreatetruecolor(4, 4);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));

    ob_start();
    imagewebp($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** Platforms that accept WebP (X, Bluesky). */
const WEBP_ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

/** Platforms that do not accept WebP (LinkedIn). */
const NO_WEBP_ALLOWED = ['image/jpeg', 'image/png', 'image/gif'];

test('image within the limit is returned byte-identical and untouched', function () {
    $bytes = compressorJpeg();

    $result = app(ImageCompressor::class)->compressToFit($bytes, strlen($bytes) + 1024, 'image/jpeg', WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes)
        ->and($result->mime)->toBe('image/jpeg');
});

test('an under-cap webp is converted to jpeg when the platform does not accept webp', function () {
    $bytes = compressorWebp();

    $result = app(ImageCompressor::class)->compressToFit($bytes, strlen($bytes) + 1_000_000, 'image/webp', NO_WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeTrue()
        ->and($result->mime)->toBe('image/jpeg')
        ->and(bin2hex(substr($result->bytes, 0, 2)))->toBe('ffd8'); // JPEG SOI marker
});

test('an under-cap webp is left untouched when the platform accepts webp', function () {
    $bytes = compressorWebp();

    $result = app(ImageCompressor::class)->compressToFit($bytes, strlen($bytes) + 1_000_000, 'image/webp', WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes)
        ->and($result->mime)->toBe('image/webp');
});

test('oversized image is compressed under the limit as jpeg when webp is not allowed', function () {
    $bytes = compressorJpeg();
    $limit = (int) (strlen($bytes) * 0.6);

    $result = app(ImageCompressor::class)->compressToFit($bytes, $limit, 'image/jpeg', NO_WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeTrue()
        ->and($result->mime)->toBe('image/jpeg')
        ->and(strlen($result->bytes))->toBeLessThanOrEqual($limit)
        ->and(strlen($result->bytes))->toBeGreaterThan(0)
        ->and(bin2hex(substr($result->bytes, 0, 2)))->toBe('ffd8'); // JPEG SOI marker
});

test('oversized image is compressed as webp when the platform allows it', function () {
    $bytes = compressorJpeg();
    $limit = (int) (strlen($bytes) * 0.6);

    $result = app(ImageCompressor::class)->compressToFit($bytes, $limit, 'image/jpeg', WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeTrue()
        ->and($result->mime)->toBe('image/webp')
        ->and(strlen($result->bytes))->toBeLessThanOrEqual($limit)
        ->and(substr($result->bytes, 0, 4))->toBe('RIFF')
        ->and(substr($result->bytes, 8, 4))->toBe('WEBP');
});

test('the highest quality that fits is chosen (quality is not capped low)', function () {
    $bytes = compressorJpeg();
    // A generous-but-shrinking limit: clearly compressible, yet not so tight that quality
    // must crater. The chosen encoding should land at/under the limit while staying close
    // to it — i.e. we did not needlessly over-compress past the budget.
    $limit = (int) (strlen($bytes) * 0.8);

    $result = app(ImageCompressor::class)->compressToFit($bytes, $limit, 'image/jpeg', NO_WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeTrue()
        ->and(strlen($result->bytes))->toBeLessThanOrEqual($limit)
        ->and(strlen($result->bytes))->toBeGreaterThan((int) ($limit * 0.5));
});

test('pathologically tiny limit falls back to the original untouched', function () {
    $bytes = compressorJpeg();

    $result = app(ImageCompressor::class)->compressToFit($bytes, 10, 'image/jpeg', WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes);
});

test('gif is skipped and returned untouched', function () {
    $gif = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";

    $result = app(ImageCompressor::class)->compressToFit($gif, 1, 'image/gif', NO_WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($gif)
        ->and($result->mime)->toBe('image/gif');
});

test('undecodable bytes over the limit fall back to the original', function () {
    $junk = str_repeat('not-an-image', 200);

    $result = app(ImageCompressor::class)->compressToFit($junk, 10, 'image/jpeg', WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($junk);
});

test('an image exceeding the pixel guard is left untouched without decoding', function () {
    $bytes = compressorJpeg(1200, 1200); // 1.44M pixels

    // maxPixels below the image's pixel count -> the decode guard trips before any canvas
    // allocation, so the oversized image is returned untouched rather than compressed.
    $result = (new ImageCompressor(maxPixels: 100))->compressToFit($bytes, (int) (strlen($bytes) * 0.6), 'image/jpeg', WEBP_ALLOWED);

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes)
        ->and($result->mime)->toBe('image/jpeg');
});

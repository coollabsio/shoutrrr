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

test('image within the limit is returned byte-identical and untouched', function () {
    $bytes = compressorJpeg();

    $result = app(ImageCompressor::class)->compressToFit($bytes, strlen($bytes) + 1024, 'image/jpeg');

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes)
        ->and($result->mime)->toBe('image/jpeg');
});

test('oversized image is compressed under the limit as jpeg', function () {
    $bytes = compressorJpeg();
    $limit = (int) (strlen($bytes) * 0.6);

    $result = app(ImageCompressor::class)->compressToFit($bytes, $limit, 'image/jpeg');

    expect($result->wasCompressed)->toBeTrue()
        ->and($result->mime)->toBe('image/jpeg')
        ->and(strlen($result->bytes))->toBeLessThanOrEqual($limit)
        ->and(strlen($result->bytes))->toBeGreaterThan(0);
});

test('pathologically tiny limit falls back to the original untouched', function () {
    $bytes = compressorJpeg();

    $result = app(ImageCompressor::class)->compressToFit($bytes, 10, 'image/jpeg');

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes);
});

test('gif is skipped and returned untouched', function () {
    $gif = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";

    $result = app(ImageCompressor::class)->compressToFit($gif, 1, 'image/gif');

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($gif)
        ->and($result->mime)->toBe('image/gif');
});

test('undecodable bytes over the limit fall back to the original', function () {
    $junk = str_repeat('not-an-image', 200);

    $result = app(ImageCompressor::class)->compressToFit($junk, 10, 'image/jpeg');

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($junk);
});

test('an image exceeding the pixel guard is left untouched without decoding', function () {
    $bytes = compressorJpeg(1200, 1200); // 1.44M pixels

    // maxPixels below the image's pixel count -> the decode guard trips before any canvas
    // allocation, so the oversized image is returned untouched rather than compressed.
    $result = (new ImageCompressor(maxPixels: 100))->compressToFit($bytes, (int) (strlen($bytes) * 0.6), 'image/jpeg');

    expect($result->wasCompressed)->toBeFalse()
        ->and($result->bytes)->toBe($bytes)
        ->and($result->mime)->toBe('image/jpeg');
});

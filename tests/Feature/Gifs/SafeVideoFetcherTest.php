<?php

use App\Enums\Platform;
use App\Support\SafeVideoFetcher;
use Illuminate\Support\Facades\Http;

/** Minimal ISO-BMFF header: 4-byte size, then 'ftyp'. */
function fakeMp4Bytes(int $padding = 1024): string
{
    return "\x00\x00\x00\x20".'ftypisom'.str_repeat("\x00", $padding);
}

test('fetches a valid mp4', function () {
    Http::fake(['https://example.com/*' => Http::response(fakeMp4Bytes())]);

    $result = app(SafeVideoFetcher::class)->fetch('https://example.com/clip.mp4');

    expect($result['mime'])->toBe('video/mp4')
        ->and($result['bytes'])->toStartWith("\x00\x00\x00\x20".'ftyp');
});

test('rejects a non-http scheme', function () {
    expect(fn () => app(SafeVideoFetcher::class)->fetch('file:///etc/passwd'))
        ->toThrow(RuntimeException::class);
});

test('rejects localhost', function () {
    expect(fn () => app(SafeVideoFetcher::class)->fetch('http://localhost/clip.mp4'))
        ->toThrow(RuntimeException::class);
});

test('rejects a private address', function () {
    expect(fn () => app(SafeVideoFetcher::class)->fetch('http://127.0.0.1/clip.mp4'))
        ->toThrow(RuntimeException::class);
});

test('rejects bytes that are not an mp4', function () {
    Http::fake(['https://example.com/*' => Http::response(str_repeat('A', 2048))]);

    expect(fn () => app(SafeVideoFetcher::class)->fetch('https://example.com/not-a-clip.mp4'))
        ->toThrow(RuntimeException::class, 'not a valid MP4');
});

test('rejects an oversize body', function () {
    // The brief's original 600 MiB fixture does not exceed the real ceiling:
    // Platform::maxVideoBytesCeiling() is 1 GiB (Facebook/Instagram/Threads),
    // not the 8 MiB SafeImageFetcher cap this class' sibling test suite might
    // suggest. Build a body exactly 1 byte over the actual ceiling, and raise
    // the memory limit only for this test since the default 512M test limit
    // (tests/Pest.php) can't hold multiple ~1 GiB copies of the fixture.
    $previousLimit = ini_set('memory_limit', '3072M');

    try {
        $oversizePadding = Platform::maxVideoBytesCeiling() - 12 + 1;
        Http::fake(['https://example.com/*' => Http::response(fakeMp4Bytes($oversizePadding))]);

        expect(fn () => app(SafeVideoFetcher::class)->fetch('https://example.com/huge.mp4'))
            ->toThrow(RuntimeException::class);
    } finally {
        ini_set('memory_limit', $previousLimit);
    }
});

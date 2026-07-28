<?php

use App\Support\SafeVideoFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    // Platform::maxVideoBytesCeiling() is 1 GiB in production, which would
    // require an ~1 GiB fixture (and multiple in-memory copies of it via
    // Http::fake()/Response::body()) to exercise honestly — too heavy for
    // CI regardless of PHP's memory_limit. Override the ceiling via config
    // for this test only, matching StorePostMediaRequest::withinPixelCeiling()'s
    // `config('media.max_image_pixels', ...)` convention, so a small fixture
    // can deterministically exceed it on any machine.
    config()->set('media.max_video_bytes', 2048);

    Http::fake(['https://example.com/*' => Http::response(fakeMp4Bytes(4096))]);

    expect(fn () => app(SafeVideoFetcher::class)->fetch('https://example.com/huge.mp4'))
        ->toThrow(RuntimeException::class);
});

test('rejects when the connection fails, without leaking the raw cURL message', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 6: Could not resolve host: example.com');
    });

    expect(fn () => app(SafeVideoFetcher::class)->fetch('https://example.com/clip.mp4'))
        ->toThrow(RuntimeException::class, 'Could not connect to the video host.');
});

test('pins curl resolution to the validated ip for hostnames (DNS-rebinding defense)', function (): void {
    $fetcher = new class(app(HttpFactory::class)) extends SafeVideoFetcher
    {
        /**
         * @param  non-empty-list<string>  $ips
         * @return array<int, list<string>>
         */
        public function exposePin(string $host, string $scheme, string $url, array $ips): array
        {
            return $this->pinnedResolution($host, $scheme, $url, $ips);
        }
    };

    // Hostname → pin to the validated IP at the scheme's default port.
    expect($fetcher->exposePin('cdn.example.com', 'https', 'https://cdn.example.com/clip.mp4', ['1.2.3.4']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:1.2.3.4']]);

    // IP-literal host needs no pinning (no name resolution at connect time).
    expect($fetcher->exposePin('1.2.3.4', 'http', 'http://1.2.3.4/clip.mp4', ['1.2.3.4']))->toBe([]);
});

test('brackets IPv6 addresses in the curl resolution pin', function (): void {
    $fetcher = new class(app(HttpFactory::class)) extends SafeVideoFetcher
    {
        /**
         * @param  non-empty-list<string>  $ips
         * @return array<int, list<string>>
         */
        public function exposePin(string $host, string $scheme, string $url, array $ips): array
        {
            return $this->pinnedResolution($host, $scheme, $url, $ips);
        }
    };

    // resolveAaaa() collects real AAAA records, so an IPv6 address genuinely can
    // reach $ips[0]. Unbracketed, the CURLOPT_RESOLVE entry is unparseable and
    // curl silently falls back to its own DNS resolution.
    expect($fetcher->exposePin('cdn.example.com', 'https', 'https://cdn.example.com/clip.mp4', ['2606:4700::1111']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:[2606:4700::1111]']]);

    // IPv4 addresses stay unbracketed.
    expect($fetcher->exposePin('cdn.example.com', 'https', 'https://cdn.example.com/clip.mp4', ['1.2.3.4']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:1.2.3.4']]);
});

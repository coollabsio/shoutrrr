<?php

use App\Support\SafeVideoFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        ->toThrow(RuntimeException::class, 'Could not download the video.');
});

test('rejects addresses in special-purpose ranges the PHP filter flags miss', function (string $url) {
    Http::fake(fn () => Http::response(fakeMp4Bytes()));

    expect(fn () => app(SafeVideoFetcher::class)->fetch($url))
        ->toThrow(RuntimeException::class, 'That host resolves to a private or reserved address.');
    Http::assertNothingSent();
})->with([
    'CGNAT' => ['http://100.64.0.1/clip.mp4'],
    'CGNAT cloud metadata' => ['http://100.100.100.200/clip.mp4'],
    'benchmarking' => ['http://198.18.0.1/clip.mp4'],
    'benchmarking upper half' => ['http://198.19.255.254/clip.mp4'],
    'IETF protocol assignments' => ['http://192.0.0.1/clip.mp4'],
    'this network' => ['http://0.1.2.3/clip.mp4'],
    'unspecified' => ['http://0.0.0.0/clip.mp4'],
    'multicast' => ['http://224.0.0.1/clip.mp4'],
    'NAT64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/clip.mp4'],
    '6to4' => ['http://[2002:a9fe:a9fe::1]/clip.mp4'],
    'Teredo' => ['http://[2001::1]/clip.mp4'],
    'IPv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/clip.mp4'],
    'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/clip.mp4'],
    'IPv4-mapped CGNAT' => ['http://[::ffff:100.100.100.200]/clip.mp4'],
]);

test('allows a public address', function (string $url) {
    Http::fake(fn () => Http::response(fakeMp4Bytes()));

    expect(app(SafeVideoFetcher::class)->fetch($url)['mime'])->toBe('video/mp4');
})->with([
    'IPv4' => ['https://93.184.215.14/clip.mp4'],
    'IPv6' => ['https://[2606:4700::1111]/clip.mp4'],
]);

test('returns the same message for HTTP errors and refused connections, logging the detail', function () {
    Log::spy();
    Http::fake([
        'https://93.184.215.14/*' => Http::response('nope', 404),
        'https://93.184.215.15/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);
    $fetcher = app(SafeVideoFetcher::class);

    expect(fn () => $fetcher->fetch('https://93.184.215.14/clip.mp4'))
        ->toThrow(RuntimeException::class, 'Could not download the video.')
        ->and(fn () => $fetcher->fetch('https://93.184.215.15/clip.mp4'))
        ->toThrow(RuntimeException::class, 'Could not download the video.');

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['status'] ?? null) === 404)->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['error'] ?? null) === 'Connection refused')->once();
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

test('fetchToFile streams a valid mp4 to the given path', function () {
    Http::fake(['https://example.com/*' => Http::response(fakeMp4Bytes())]);
    $path = tempnam(sys_get_temp_dir(), 'test');

    app(SafeVideoFetcher::class)->fetchToFile('https://example.com/video.mp4', $path);

    expect(file_get_contents($path))->toBe(fakeMp4Bytes());
    @unlink($path);
});

test('fetchToFile rejects an oversize or non-mp4 download', function (string $body) {
    config()->set('media.max_video_bytes', 2048);
    Http::fake(['https://example.com/*' => Http::response($body)]);
    $path = tempnam(sys_get_temp_dir(), 'test');

    expect(fn () => app(SafeVideoFetcher::class)->fetchToFile('https://example.com/video.mp4', $path))
        ->toThrow(RuntimeException::class);
    @unlink($path);
})->with([
    'oversize' => [fakeMp4Bytes(4096)],
    'not an mp4' => [str_repeat('A', 1024)],
]);

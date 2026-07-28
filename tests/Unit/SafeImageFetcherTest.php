<?php

use App\Support\SafeImageFetcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

test('rejects non-http(s) schemes', function (): void {
    $fetcher = new SafeImageFetcher(app(HttpFactory::class));
    expect(fn () => $fetcher->fetch('ftp://example.com/x.png'))->toThrow(RuntimeException::class);
});

test('rejects private and loopback hosts', function (): void {
    $fetcher = new SafeImageFetcher(app(HttpFactory::class));
    expect(fn () => $fetcher->fetch('http://localhost/x.png'))->toThrow(RuntimeException::class);
    expect(fn () => $fetcher->fetch('http://127.0.0.1/x.png'))->toThrow(RuntimeException::class);
    expect(fn () => $fetcher->fetch('http://169.254.169.254/latest/meta-data'))->toThrow(RuntimeException::class);
    expect(fn () => $fetcher->fetch('http://192.168.1.10/x.png'))->toThrow(RuntimeException::class);
});

test('fetches a public image and returns its bytes and mime', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    // example.com resolves to public IPs; Http::fake intercepts the HTTP call.
    Http::fake(['https://example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $fetcher = new SafeImageFetcher(app(HttpFactory::class));
    $result = $fetcher->fetch('https://example.com/a.png');

    expect($result['mime'])->toBe('image/png');
    expect($result['bytes'])->toBe($png);
});

test('pins curl resolution to the validated ip for hostnames (DNS-rebinding defense)', function (): void {
    $fetcher = new class(app(HttpFactory::class)) extends SafeImageFetcher
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
    expect($fetcher->exposePin('cdn.example.com', 'https', 'https://cdn.example.com/a.png', ['1.2.3.4']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:1.2.3.4']]);

    // Explicit port is honoured.
    expect($fetcher->exposePin('cdn.example.com', 'http', 'http://cdn.example.com:8080/a.png', ['1.2.3.4']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:8080:1.2.3.4']]);

    // IP-literal host needs no pinning (no name resolution at connect time).
    expect($fetcher->exposePin('1.2.3.4', 'http', 'http://1.2.3.4/a.png', ['1.2.3.4']))->toBe([]);
});

test('brackets IPv6 addresses in the curl resolution pin', function (): void {
    $fetcher = new class(app(HttpFactory::class)) extends SafeImageFetcher
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

    // IPv6 addresses must be bracketed, or the CURLOPT_RESOLVE entry is unparseable
    // and curl silently falls back to its own DNS resolution.
    expect($fetcher->exposePin('cdn.example.com', 'https', 'https://cdn.example.com/a.png', ['2606:4700::1111']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:[2606:4700::1111]']]);

    // IPv4 addresses stay unbracketed.
    expect($fetcher->exposePin('cdn.example.com', 'https', 'https://cdn.example.com/a.png', ['1.2.3.4']))
        ->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:1.2.3.4']]);
});

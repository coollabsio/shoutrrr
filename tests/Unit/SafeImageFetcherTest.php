<?php

use App\Support\SafeImageFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function onePixelPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

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

test('rejects addresses in special-purpose ranges the PHP filter flags miss', function (string $url): void {
    Http::fake(fn () => Http::response(onePixelPng(), 200, ['Content-Type' => 'image/png']));

    expect(fn () => (new SafeImageFetcher(app(HttpFactory::class)))->fetch($url))
        ->toThrow(RuntimeException::class, 'That host resolves to a private or reserved address.');
    Http::assertNothingSent();
})->with([
    'CGNAT cloud metadata' => ['http://100.100.100.200/a.png'],
    'benchmarking' => ['http://198.18.0.1/a.png'],
    'IETF protocol assignments' => ['http://192.0.0.1/a.png'],
    'this network' => ['http://0.0.0.0/a.png'],
    'NAT64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/a.png'],
    '6to4' => ['http://[2002:a9fe:a9fe::1]/a.png'],
    'IPv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/a.png'],
]);

test('allows a public ip literal', function (): void {
    Http::fake(fn () => Http::response(onePixelPng(), 200, ['Content-Type' => 'image/png']));

    expect((new SafeImageFetcher(app(HttpFactory::class)))->fetch('https://93.184.215.14/a.png')['mime'])->toBe('image/png');
});

test('returns the same message for HTTP errors and refused connections, logging the detail', function (): void {
    Log::spy();
    Http::fake([
        'https://93.184.215.14/*' => Http::response('nope', 503),
        'https://93.184.215.15/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);
    $fetcher = new SafeImageFetcher(app(HttpFactory::class));

    expect(fn () => $fetcher->fetch('https://93.184.215.14/a.png'))
        ->toThrow(RuntimeException::class, 'Could not download the image.')
        ->and(fn () => $fetcher->fetch('https://93.184.215.15/a.png'))
        ->toThrow(RuntimeException::class, 'Could not download the image.');

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['status'] ?? null) === 503)->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['error'] ?? null) === 'Connection refused')->once();
});

<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\PinsCurlResolution;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Downloads an image from a user-supplied URL with SSRF protections: scheme
 * allow-list, host/DNS private-range rejection, IP pinning, size cap, and
 * content-type + magic-byte validation.
 *
 * Redirect safety: redirects are disabled (`allow_redirects: false`) so a public
 * URL cannot 302 to a private host that bypasses the pre-fetch check.
 *
 * DNS-rebinding safety: the hostname is resolved once and validated, then curl is
 * pinned to that exact IP via CURLOPT_RESOLVE. This closes the time-of-check /
 * time-of-use gap where an attacker-controlled short-TTL record could return a
 * public IP for our check and a private IP for curl's own connect-time lookup. TLS
 * SNI and certificate verification still use the original hostname, so HTTPS is
 * unaffected.
 */
class SafeImageFetcher
{
    use PinsCurlResolution;

    private const int MAX_BYTES = 8 * 1024 * 1024; // 8 MiB

    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * One message for refused connections and HTTP errors alike, so the
     * caller cannot use the fetcher to tell open ports from closed ones.
     */
    private const string DOWNLOAD_FAILED = 'Could not download the image.';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Fetch an image from the given URL after SSRF validation.
     *
     * @return array{bytes: string, mime: string}
     *
     * @throws RuntimeException if the URL is blocked or the response is not a valid image.
     */
    public function fetch(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Image URL must use http or https.');
        }

        $rawHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($rawHost) || $rawHost === '') {
            throw new RuntimeException('Image URL has no host.');
        }

        $host = strtolower(trim($rawHost, '[]'));
        $ips = PublicIp::resolve($host);

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => $this->pinnedResolution($host, (string) $scheme, $url, $ips),
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::info('Image download failed to connect.', ['url' => $url, 'error' => $e->getMessage()]);

            throw new RuntimeException(self::DOWNLOAD_FAILED);
        }

        if (! $response->successful()) {
            Log::info('Image download returned an error status.', ['url' => $url, 'status' => $response->status()]);

            throw new RuntimeException(self::DOWNLOAD_FAILED);
        }

        $bytes = $response->body();
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Image exceeds the 8 MiB limit.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info['mime'], self::ALLOWED_MIME, true)) {
            throw new RuntimeException('URL did not return a supported image (jpeg, png, webp, gif).');
        }

        return ['bytes' => $bytes, 'mime' => (string) $info['mime']];
    }
}

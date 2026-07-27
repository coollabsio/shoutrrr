<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Downloads an MP4 from a user-supplied URL with the same SSRF protections as
 * SafeImageFetcher (scheme allow-list, private-range rejection, IP pinning, no
 * redirects), but capped at the video ceiling and validated by the ISO-BMFF
 * `ftyp` box rather than by image headers.
 */
class SafeVideoFetcher
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{bytes: string, mime: string}
     *
     * @throws RuntimeException if the URL is blocked or the response is not an MP4.
     */
    public function fetch(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Video URL must use http or https.');
        }

        $rawHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($rawHost) || $rawHost === '') {
            throw new RuntimeException('Video URL has no host.');
        }

        $host = strtolower(trim($rawHost, '[]'));
        $ips = $this->resolveValidatedIps($host);

        try {
            $response = $this->http
                ->timeout(20)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => $this->pinnedResolution($host, (string) $scheme, $url, $ips),
                ])
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not connect to the video host.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Could not download the clip (HTTP '.$response->status().').');
        }

        $bytes = $response->body();

        if (strlen($bytes) > Platform::maxVideoBytesCeiling()) {
            throw new RuntimeException('Clip exceeds the maximum allowed video size.');
        }

        // Same check PostVideoUploadController applies to direct-to-storage
        // uploads: an ISO-BMFF `ftyp` box at offset 4.
        if (substr($bytes, 4, 4) !== 'ftyp') {
            throw new RuntimeException('The downloaded clip is not a valid MP4 video.');
        }

        return ['bytes' => $bytes, 'mime' => 'video/mp4'];
    }

    // --- SSRF helpers: copied verbatim from SafeImageFetcher ----------------

    /**
     * Resolve a hostname (or accept an IP literal) and reject if any resolved
     * address is private or reserved.
     *
     * @return non-empty-list<string>
     *
     * @throws RuntimeException
     */
    private function resolveValidatedIps(string $host): array
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException('That host is not allowed.');
        }

        // If the host is already an IP literal, validate it directly. Otherwise
        // resolve A (IPv4) and AAAA (IPv6) records.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_merge(gethostbynamel($host) ?: [], $this->resolveAaaa($host));

        if ($ips === []) {
            throw new RuntimeException('That host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('That host resolves to a private or reserved address.');
            }
        }

        return $ips;
    }

    /**
     * Build the curl option that pins the connection to a pre-validated IP. No
     * pinning is needed when the host is already an IP literal (no name resolution
     * happens at connect time, so there is no rebinding window).
     *
     * @param  non-empty-list<string>  $ips
     * @return array<int, list<string>>
     */
    protected function pinnedResolution(string $host, string $scheme, string $url, array $ips): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);

        return [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ips[0])]];
    }

    /**
     * @return list<string>
     */
    private function resolveAaaa(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $r): ?string => $r['ipv6'] ?? null,
            $records,
        )));
    }
}

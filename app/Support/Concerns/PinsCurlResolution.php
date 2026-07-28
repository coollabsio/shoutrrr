<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/**
 * Shared by SafeImageFetcher and SafeVideoFetcher: builds the CURLOPT_RESOLVE
 * option that pins a connection to a pre-validated IP, closing the DNS-rebinding
 * time-of-check/time-of-use gap between the SSRF validation and curl's own
 * connect-time resolution.
 */
trait PinsCurlResolution
{
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

        // CURLOPT_RESOLVE entries are HOST:PORT:ADDRESS. An IPv6 address must be
        // bracketed here or the entry is unparseable and curl silently falls back
        // to its own DNS resolution, voiding the rebinding protection.
        $ip = $ips[0];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ip = "[{$ip}]";
        }

        return [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ip)]];
    }
}

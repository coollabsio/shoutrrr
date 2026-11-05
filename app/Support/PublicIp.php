<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard shared by SafeImageFetcher and SafeVideoFetcher: resolves a host
 * and rejects it unless every address is publicly routable.
 */
final class PublicIp
{
    /**
     * Special-purpose ranges that PHP's FILTER_FLAG_NO_PRIV_RANGE and
     * FILTER_FLAG_NO_RES_RANGE let through, plus IPv6 prefixes that embed an
     * IPv4 address (NAT64, 6to4, Teredo, mapped) and can reach internal hosts.
     */
    private const array DENIED_RANGES = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2002::/16',
        'ff00::/8',
    ];

    /**
     * Resolve a hostname (or accept an IP literal) and reject it if any
     * resolved address is not public.
     *
     * @return non-empty-list<string>
     *
     * @throws RuntimeException
     */
    public static function resolve(string $host): array
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException('That host is not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_merge(gethostbynamel($host) ?: [], self::resolveAaaa($host));

        if ($ips === []) {
            throw new RuntimeException('That host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new RuntimeException('That host resolves to a private or reserved address.');
            }
        }

        return $ips;
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($ip, self::DENIED_RANGES);
    }

    /**
     * @return list<string>
     */
    private static function resolveAaaa(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $r): ?string => $r['ipv6'] ?? null,
            $records,
        )));
    }
}

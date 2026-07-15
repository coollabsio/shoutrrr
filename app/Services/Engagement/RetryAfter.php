<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;

/**
 * Reads how long to wait after a rate-limited response, from whichever standard
 * header the platform sends: `Retry-After` (delta seconds), or an epoch reset in
 * `x-rate-limit-reset` (X) / `ratelimit-reset` (Bluesky / IETF draft). Clamped to
 * a sane band so a bogus header can neither hammer the API nor park an account for
 * an unbounded time.
 */
final class RetryAfter
{
    private const int MIN_SECONDS = 1;

    private const int MAX_SECONDS = 3600;

    public static function seconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter !== '') {
            // RFC 7231: Retry-After is either a delta in seconds or an HTTP-date.
            if (is_numeric($retryAfter)) {
                return self::clamp((int) $retryAfter);
            }

            $timestamp = strtotime($retryAfter);

            if ($timestamp !== false) {
                return self::clamp($timestamp - Date::now()->getTimestamp());
            }
        }

        foreach (['x-rate-limit-reset', 'ratelimit-reset'] as $header) {
            $reset = $response->header($header);

            if ($reset !== '' && is_numeric($reset)) {
                return self::clamp((int) $reset - Date::now()->getTimestamp());
            }
        }

        return null;
    }

    private static function clamp(int $seconds): int
    {
        return max(self::MIN_SECONDS, min($seconds, self::MAX_SECONDS));
    }
}

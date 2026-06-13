<?php

declare(strict_types=1);

namespace App\Services\Publishing;

class BackoffSchedule
{
    private const int BASE_SECONDS = 60;

    private const int CAP_SECONDS = 3600;

    /**
     * Exponential backoff (base 60s, doubling per attempt, capped ~1h) plus up to
     * 10% bounded jitter to de-correlate retries across targets.
     */
    public function nextDelaySeconds(int $attempt): int
    {
        $exponent = max(0, $attempt - 1);
        $base = min(self::BASE_SECONDS * (2 ** $exponent), self::CAP_SECONDS);
        $jitter = random_int(0, (int) ($base * 0.10));

        return (int) $base + $jitter;
    }
}

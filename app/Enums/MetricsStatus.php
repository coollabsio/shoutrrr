<?php

declare(strict_types=1);

namespace App\Enums;

enum MetricsStatus: string
{
    case Ok = 'ok';
    case Unsupported = 'unsupported';
    case RateLimited = 'rate_limited';
    case Failed = 'failed';

    /** Whether the poller should keep trying; `Unsupported` is terminal. */
    public function isPollable(): bool
    {
        return $this !== self::Unsupported;
    }
}

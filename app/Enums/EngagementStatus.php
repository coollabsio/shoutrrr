<?php

declare(strict_types=1);

namespace App\Enums;

enum EngagementStatus: string
{
    case Ok = 'ok';
    case Unsupported = 'unsupported';
    case RateLimited = 'rate_limited';
    case AuthExpired = 'auth_expired';
    case Failed = 'failed';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }
}

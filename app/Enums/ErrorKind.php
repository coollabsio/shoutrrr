<?php

declare(strict_types=1);

namespace App\Enums;

enum ErrorKind: string
{
    case RateLimited = 'rate_limited';
    case AuthExpired = 'auth_expired';
    case Validation = 'validation';
    case DuplicateContent = 'duplicate_content';
    case Network = 'network';
    case ServerError = 'server_error';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::AuthExpired, self::Network, self::ServerError => true,
            self::Validation, self::DuplicateContent, self::Unknown => false,
        };
    }
}

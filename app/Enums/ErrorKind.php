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
    case MediaProcessing = 'media_processing';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::AuthExpired, self::Network, self::ServerError, self::MediaProcessing => true,
            self::Validation, self::DuplicateContent, self::Unknown => false,
        };
    }
}

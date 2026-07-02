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
    /** A required server-side capability is missing (e.g. a media tool isn't installed); retrying can't fix it. */
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::Network, self::ServerError, self::MediaProcessing => true,
            self::AuthExpired, self::Validation, self::DuplicateContent, self::Unsupported, self::Unknown => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorKind;
use RuntimeException;

class TokenRefreshException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorKind $errorKind = ErrorKind::AuthExpired,
        public readonly ?int $retryAfter = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}

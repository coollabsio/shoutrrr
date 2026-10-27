<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A high-level agent intent was refused before it changed workspace state.
 */
class AgentIntentRejectedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }
}

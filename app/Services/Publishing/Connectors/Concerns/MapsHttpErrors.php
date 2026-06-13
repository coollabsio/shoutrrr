<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors\Concerns;

use App\Enums\ErrorKind;
use Illuminate\Http\Client\Response;

trait MapsHttpErrors
{
    private function classifyStatus(int $status): ErrorKind
    {
        return match (true) {
            $status === 429 => ErrorKind::RateLimited,
            $status === 401 => ErrorKind::AuthExpired,
            // 403 is terminal by default (permissions, suspended, duplicate). Connectors
            // may inspect the body to refine 403 into DuplicateContent where applicable.
            $status === 403 => ErrorKind::Validation,
            $status === 422 || $status === 400 => ErrorKind::Validation,
            $status >= 500 => ErrorKind::ServerError,
            default => ErrorKind::Unknown,
        };
    }

    /**
     * Extract the `Retry-After` header (in seconds) when present, so the publishing
     * job can honour the platform's backoff rather than guessing.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '' || ! is_numeric($header)) {
            return null;
        }

        return (int) $header;
    }

    private function excerpt(Response $response): string
    {
        return mb_substr($response->body(), 0, 500);
    }
}

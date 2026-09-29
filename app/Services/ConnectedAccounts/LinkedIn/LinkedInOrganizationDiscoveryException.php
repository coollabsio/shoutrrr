<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\LinkedIn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LinkedIn failed to list the member's Pages. Kept distinct from an empty result
 * so the connect flow doesn't tell users they administer no Pages when LinkedIn
 * actually throttled or rejected the call. The code is the HTTP status (0 when
 * the request never got a response).
 */
final class LinkedInOrganizationDiscoveryException extends RuntimeException
{
    public static function fromResponse(Response $response): self
    {
        return new self(
            sprintf('LinkedIn returned HTTP %d: %s', $response->status(), Str::limit($response->body(), 300)),
            $response->status(),
        );
    }

    public static function fromConnection(ConnectionException $e): self
    {
        return new self($e->getMessage(), 0, $e);
    }

    public function isRateLimited(): bool
    {
        return $this->getCode() === 429;
    }

    public function isForbidden(): bool
    {
        return $this->getCode() === 403;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

/**
 * Outcome of a LinkedIn organization lookup / de-risk probe.
 *
 * `gated` is the signal that matters most in production: LinkedIn returns 403
 * when the app lacks the partner-gated `rw_organization_admin` scope (the
 * Community Management API product), which means org lookup by vanity name is
 * unavailable and the org URN must be supplied manually.
 */
final readonly class LinkedInOrgLookupResult
{
    private function __construct(
        public int $status,
        public ?string $urn,
        public ?string $name,
        public bool $gated,
        public ?string $error,
    ) {}

    public static function success(string $urn, ?string $name, int $status = 200): self
    {
        return new self(status: $status, urn: $urn, name: $name, gated: false, error: null);
    }

    public static function gated(int $status = 403): self
    {
        return new self(status: $status, urn: null, name: null, gated: true, error: null);
    }

    public static function notFound(int $status = 404): self
    {
        return new self(status: $status, urn: null, name: null, gated: false, error: null);
    }

    public static function error(string $message, int $status = 0): self
    {
        return new self(status: $status, urn: null, name: null, gated: false, error: $message);
    }

    public function isSuccessful(): bool
    {
        return $this->urn !== null;
    }
}

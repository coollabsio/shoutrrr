<?php

declare(strict_types=1);

namespace App\Dto\Engagement;

use App\Enums\EngagementStatus;

/**
 * Outcome of a side-effecting reply action (like / unlike / delete). `remoteId`
 * carries the platform's like-record id on a successful like, so a later unlike
 * can target the exact record (Bluesky); it is null for actions that don't
 * produce one.
 */
final readonly class ReplyActionResult
{
    private function __construct(
        public EngagementStatus $status,
        public ?string $remoteId = null,
        public ?string $message = null,
    ) {}

    public static function ok(?string $remoteId = null): self
    {
        return new self(EngagementStatus::Ok, $remoteId);
    }

    public static function unsupported(?string $message = null): self
    {
        return new self(EngagementStatus::Unsupported, message: $message);
    }

    public static function rateLimited(?string $message = null): self
    {
        return new self(EngagementStatus::RateLimited, message: $message);
    }

    public static function authExpired(?string $message = null): self
    {
        return new self(EngagementStatus::AuthExpired, message: $message);
    }

    public static function failed(?string $message = null): self
    {
        return new self(EngagementStatus::Failed, message: $message);
    }

    public function isOk(): bool
    {
        return $this->status->isOk();
    }
}

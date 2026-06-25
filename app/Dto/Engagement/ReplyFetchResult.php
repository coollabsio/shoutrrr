<?php

declare(strict_types=1);

namespace App\Dto\Engagement;

use App\Enums\EngagementStatus;

final readonly class ReplyFetchResult
{
    /** @param list<FetchedReply> $replies */
    private function __construct(
        public EngagementStatus $status,
        public array $replies = [],
        public ?string $message = null,
    ) {}

    /** @param list<FetchedReply> $replies */
    public static function ok(array $replies): self
    {
        return new self(EngagementStatus::Ok, $replies);
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

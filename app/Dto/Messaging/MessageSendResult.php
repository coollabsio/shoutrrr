<?php

declare(strict_types=1);

namespace App\Dto\Messaging;

use App\Enums\EngagementStatus;

final readonly class MessageSendResult
{
    private function __construct(
        public EngagementStatus $status,
        public ?string $remoteMessageId = null,
        public ?int $retryAfterSeconds = null,
        public ?string $excerpt = null,
    ) {}

    public static function ok(string $remoteMessageId): self
    {
        return new self(EngagementStatus::Ok, remoteMessageId: $remoteMessageId);
    }

    public static function rateLimited(?string $excerpt, ?int $retryAfter): self
    {
        return new self(EngagementStatus::RateLimited, retryAfterSeconds: $retryAfter, excerpt: $excerpt);
    }

    public static function authExpired(?string $excerpt): self
    {
        return new self(EngagementStatus::AuthExpired, excerpt: $excerpt);
    }

    public static function unsupported(?string $excerpt): self
    {
        return new self(EngagementStatus::Unsupported, excerpt: $excerpt);
    }

    public static function failed(?string $excerpt): self
    {
        return new self(EngagementStatus::Failed, excerpt: $excerpt);
    }

    /** Same outcome, restated for the user. */
    public function withExcerpt(string $excerpt): self
    {
        return new self($this->status, $this->remoteMessageId, $this->retryAfterSeconds, $excerpt);
    }

    public function isOk(): bool
    {
        return $this->status->isOk();
    }
}

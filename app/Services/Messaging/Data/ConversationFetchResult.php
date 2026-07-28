<?php

declare(strict_types=1);

namespace App\Services\Messaging\Data;

use App\Enums\EngagementStatus;

final readonly class ConversationFetchResult
{
    /** @param list<FetchedConversation> $conversations */
    private function __construct(
        public EngagementStatus $status,
        public array $conversations = [],
        public ?string $cursor = null,
        public ?int $retryAfterSeconds = null,
        public ?string $excerpt = null,
    ) {}

    /** @param list<FetchedConversation> $conversations */
    public static function ok(array $conversations, ?string $cursor = null): self
    {
        return new self(EngagementStatus::Ok, $conversations, $cursor);
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

    public function isOk(): bool
    {
        return $this->status->isOk();
    }
}

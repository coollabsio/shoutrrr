<?php

declare(strict_types=1);

namespace App\Dto\Engagement;

use App\Enums\EngagementStatus;

final readonly class ReplyPostResult
{
    private function __construct(
        public EngagementStatus $status,
        public ?string $remoteReplyId = null,
        public ?string $remoteCid = null,
        public ?string $message = null,
        public ?string $processingContainerId = null,
    ) {}

    public static function ok(string $remoteReplyId, ?string $remoteCid = null): self
    {
        return new self(EngagementStatus::Ok, $remoteReplyId, $remoteCid);
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

    /**
     * The platform has not finished the reply. Anything that cannot wait for it
     * sees an ordinary failure.
     */
    public static function processing(string $containerId): self
    {
        return new self(
            EngagementStatus::Failed,
            message: 'The platform is still processing the reply. Try again in a moment.',
            processingContainerId: $containerId,
        );
    }

    public function isOk(): bool
    {
        return $this->status->isOk();
    }
}

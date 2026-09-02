<?php

declare(strict_types=1);

namespace App\Dto\NativeRead;

use App\Enums\MetricsStatus;

final readonly class RecentPostsResult
{
    /**
     * @param  list<NativePost>  $posts
     */
    private function __construct(
        public MetricsStatus $status,
        public array $posts,
        public ?string $newestRemoteId,
        public ?string $message,
    ) {}

    /**
     * @param  list<NativePost>  $posts
     */
    public static function ok(array $posts, ?string $newestRemoteId): self
    {
        return new self(MetricsStatus::Ok, $posts, $newestRemoteId, null);
    }

    public static function unsupported(string $message): self
    {
        return new self(MetricsStatus::Unsupported, [], null, $message);
    }

    public static function rateLimited(string $message): self
    {
        return new self(MetricsStatus::RateLimited, [], null, $message);
    }

    public static function failed(string $message): self
    {
        return new self(MetricsStatus::Failed, [], null, $message);
    }

    public function isOk(): bool
    {
        return $this->status === MetricsStatus::Ok;
    }
}

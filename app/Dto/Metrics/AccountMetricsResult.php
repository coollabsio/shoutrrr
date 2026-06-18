<?php

declare(strict_types=1);

namespace App\Dto\Metrics;

use App\Enums\MetricsStatus;

final readonly class AccountMetricsResult
{
    /** @param array<string, mixed>|null $raw */
    private function __construct(
        public MetricsStatus $status,
        public int $followers = 0,
        public ?int $following = null,
        public ?int $postsCount = null,
        public ?array $raw = null,
        public ?string $message = null,
    ) {}

    /** @param array<string, mixed>|null $raw */
    public static function ok(int $followers, ?int $following = null, ?int $postsCount = null, ?array $raw = null): self
    {
        return new self(MetricsStatus::Ok, $followers, $following, $postsCount, $raw);
    }

    public static function unsupported(?string $message = null): self
    {
        return new self(MetricsStatus::Unsupported, message: $message);
    }

    public static function rateLimited(?string $message = null): self
    {
        return new self(MetricsStatus::RateLimited, message: $message);
    }

    public static function failed(?string $message = null): self
    {
        return new self(MetricsStatus::Failed, message: $message);
    }

    public function isOk(): bool
    {
        return $this->status === MetricsStatus::Ok;
    }
}

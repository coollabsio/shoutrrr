<?php

declare(strict_types=1);

namespace App\Dto\Metrics;

use App\Enums\MetricsStatus;

final readonly class PostMetricsResult
{
    /** @param array<string, mixed>|null $raw */
    private function __construct(
        public MetricsStatus $status,
        public int $likes = 0,
        public int $comments = 0,
        public int $reposts = 0,
        public ?int $impressions = null,
        public ?array $raw = null,
        public ?string $message = null,
    ) {}

    /** @param array<string, mixed>|null $raw */
    public static function ok(int $likes, int $comments, int $reposts, ?int $impressions = null, ?array $raw = null): self
    {
        return new self(MetricsStatus::Ok, $likes, $comments, $reposts, $impressions, $raw);
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

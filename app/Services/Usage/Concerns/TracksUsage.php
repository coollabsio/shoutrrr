<?php

declare(strict_types=1);

namespace App\Services\Usage\Concerns;

use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Services\Usage\UsageRecorder;
use Illuminate\Http\Client\Response;

trait TracksUsage
{
    /**
     * Record a metered platform call. Pass an explicit $succeeded when the caller
     * tolerates a non-2xx status as success (e.g. a 404 delete or a 409 that still
     * yields a usable id); otherwise success is derived from the HTTP status.
     */
    protected function meter(
        UsageCategory $category,
        string $operation,
        ConnectedAccount $account,
        Response $response,
        int $quotaWeight = 1,
        ?bool $succeeded = null,
    ): void {
        app(UsageRecorder::class)->record(
            category: $category,
            operation: $operation,
            workspaceId: $account->workspace_id,
            platform: $account->platform,
            quotaWeight: $quotaWeight,
            succeeded: $succeeded ?? $response->successful(),
            meta: $this->usageMeta($response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function usageMeta(Response $response): array
    {
        return array_filter([
            'status' => $response->status(),
            // Header conventions differ by platform: X sends x-rate-limit-*, while
            // Bluesky/atproto sends the IETF-draft ratelimit-* (no x- prefix).
            'rate_limit' => $response->header('x-rate-limit-limit') ?: $response->header('ratelimit-limit'),
            'rate_remaining' => $response->header('x-rate-limit-remaining') ?: $response->header('ratelimit-remaining'),
            'rate_reset' => $response->header('x-rate-limit-reset') ?: $response->header('ratelimit-reset'),
        ], static fn (int|string $value): bool => $value !== '');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Usage\Concerns;

use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Services\Usage\UsageRecorder;
use Illuminate\Http\Client\Response;

trait TracksUsage
{
    protected function meter(
        UsageCategory $category,
        string $operation,
        ConnectedAccount $account,
        Response $response,
        int $quotaWeight = 1,
    ): void {
        app(UsageRecorder::class)->record(
            category: $category,
            operation: $operation,
            workspaceId: $account->workspace_id,
            platform: $account->platform,
            quotaWeight: $quotaWeight,
            succeeded: $response->successful(),
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
            'rate_limit' => $response->header('x-rate-limit-limit'),
            'rate_remaining' => $response->header('x-rate-limit-remaining'),
            'rate_reset' => $response->header('x-rate-limit-reset'),
        ], static fn (int|string $value): bool => $value !== '');
    }
}

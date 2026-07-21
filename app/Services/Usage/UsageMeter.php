<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\Platform;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

class UsageMeter
{
    public function currentPeriodQuota(string $workspaceId, ?Platform $platform = null, ?string $operation = null): int
    {
        return (int) $this->query($workspaceId, $platform, $operation)->sum('total_quota');
    }

    public function currentPeriodCount(string $workspaceId, ?Platform $platform = null, ?string $operation = null): int
    {
        return (int) $this->query($workspaceId, $platform, $operation)->sum('event_count');
    }

    public function currentPeriodCostMicrousd(string $workspaceId, ?Platform $platform = null, ?string $operation = null): int
    {
        return (int) $this->query($workspaceId, $platform, $operation)->sum('total_cost_microusd');
    }

    /**
     * Count successful usage events since an arbitrary point in time. Used for
     * billing-anchored periods that do not align with the calendar-month counter
     * rows (events are retained well past any open billing cycle before pruning).
     */
    public function countSince(string $workspaceId, CarbonImmutable $since, ?Platform $platform = null, ?string $operation = null): int
    {
        $query = UsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('succeeded', true)
            ->where('occurred_at', '>=', $since);

        if ($platform !== null) {
            $query->where('platform', $platform->value);
        }

        if ($operation !== null) {
            $query->where('operation', $operation);
        }

        return $query->count();
    }

    public function costSinceMicrousd(string $workspaceId, CarbonImmutable $since, ?Platform $platform = null, ?string $operation = null): int
    {
        $query = UsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('succeeded', true)
            ->where('occurred_at', '>=', $since);

        if ($platform !== null) {
            $query->where('platform', $platform->value);
        }

        if ($operation !== null) {
            $query->where('operation', $operation);
        }

        return (int) $query->sum('cost_weight_microusd');
    }

    public function remaining(string $workspaceId, int $limit, ?Platform $platform = null, ?string $operation = null): int
    {
        return max(0, $limit - $this->currentPeriodQuota($workspaceId, $platform, $operation));
    }

    public function remainingCostMicrousd(string $workspaceId, int $limitMicrousd, ?Platform $platform = null, ?string $operation = null): int
    {
        return max(0, $limitMicrousd - $this->currentPeriodCostMicrousd($workspaceId, $platform, $operation));
    }

    /**
     * @return array<string, int> operation => total_quota for the current calendar month
     */
    public function currentPeriodQuotaByOperation(string $workspaceId, Platform $platform): array
    {
        return $this->query($workspaceId, $platform, null)
            ->selectRaw('operation, sum(total_quota) as quota')
            ->groupBy('operation')
            ->pluck('quota', 'operation')
            ->map(fn ($quota): int => (int) $quota)
            ->all();
    }

    /**
     * @return array<string, int> operation => sum(quota_weight) for succeeded events since $since
     */
    public function quotaByOperationSince(string $workspaceId, CarbonImmutable $since, Platform $platform): array
    {
        return UsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('succeeded', true)
            ->where('platform', $platform->value)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('operation, sum(quota_weight) as quota')
            ->groupBy('operation')
            ->pluck('quota', 'operation')
            ->map(fn ($quota): int => (int) $quota)
            ->all();
    }

    /**
     * @return Builder<UsagePeriodCounter>
     */
    private function query(string $workspaceId, ?Platform $platform, ?string $operation): Builder
    {
        $periodStart = CarbonImmutable::instance(Date::now())->startOfMonth()->toDateString();

        $query = UsagePeriodCounter::query()
            ->where('workspace_id', $workspaceId)
            ->where('period_start', $periodStart);

        if ($platform !== null) {
            $query->where('platform', $platform->value);
        }

        if ($operation !== null) {
            $query->where('operation', $operation);
        }

        return $query;
    }
}

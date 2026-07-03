<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\Platform;
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

    public function remaining(string $workspaceId, int $limit, ?Platform $platform = null, ?string $operation = null): int
    {
        return max(0, $limit - $this->currentPeriodQuota($workspaceId, $platform, $operation));
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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class ReconcileUsageCounters extends Command
{
    protected $signature = 'usage:reconcile';

    protected $description = 'Recompute the open-period usage counters from raw succeeded usage events.';

    public function handle(InstanceSettings $settings): int
    {
        if (! $settings->usageTrackingEnabled()) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::instance(Date::now());
        $periodStart = $now->startOfMonth();
        $periodEnd = $now->endOfMonth();

        $aggregates = UsageEvent::query()
            ->where('succeeded', true)
            ->whereNotNull('workspace_id')
            ->whereBetween('occurred_at', [$periodStart, $periodEnd->endOfDay()])
            ->selectRaw("workspace_id, category, coalesce(platform, 'none') as platform, operation, count(*) as event_count, sum(quota_weight) as total_quota")
            ->groupBy('workspace_id', 'category', 'platform', 'operation')
            ->toBase()
            ->get();

        foreach ($aggregates as $row) {
            UsagePeriodCounter::query()->updateOrCreate(
                [
                    'workspace_id' => $row->workspace_id,
                    'period_start' => $periodStart->toDateString(),
                    'category' => $row->category,
                    'platform' => $row->platform,
                    'operation' => $row->operation,
                ],
                [
                    'period_end' => $periodEnd->toDateString(),
                    'event_count' => (int) $row->event_count,
                    'total_quota' => (int) $row->total_quota,
                ],
            );
        }

        return self::SUCCESS;
    }
}

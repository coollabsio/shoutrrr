<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class PruneUsageEvents extends Command
{
    protected $signature = 'usage:prune';

    protected $description = 'Delete usage events older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) config('usage.retention_days', 180);
        $now = CarbonImmutable::instance(Date::now());

        // Never prune events inside the open billing period: usage:reconcile still
        // recomputes that period's counters from raw events, so deleting them would
        // shrink the totals the counters are meant to durably hold. This keeps a low
        // USAGE_RETENTION_DAYS from silently corrupting the current month's usage.
        $cutoff = $now->subDays($days)->min($now->startOfMonth());

        UsageEvent::query()->where('occurred_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}

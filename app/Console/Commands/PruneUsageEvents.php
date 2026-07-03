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

        // Chunked delete: usage_events grows a row per metered API attempt, so a
        // single unbounded DELETE risks long lock times / replication lag. chunkById
        // keeps each batch small and is portable across sqlite/MySQL/Postgres (unlike
        // DELETE ... LIMIT). Deleting the just-read ids never skips rows because each
        // chunk pages forward by id.
        UsageEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(1000, function ($events): void {
                UsageEvent::query()->whereIn('id', $events->pluck('id'))->delete();
            });

        return self::SUCCESS;
    }
}

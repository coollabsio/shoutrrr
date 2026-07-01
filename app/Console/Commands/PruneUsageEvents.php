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
        $cutoff = CarbonImmutable::instance(Date::now())->subDays($days);

        UsageEvent::query()->where('occurred_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}

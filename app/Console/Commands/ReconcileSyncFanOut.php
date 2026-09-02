<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\Sync\SyncFanOutService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

class ReconcileSyncFanOut extends Command
{
    protected $signature = 'sync:reconcile';

    protected $description = 'Backstop that fans out any recently published source posts missed by the publish event.';

    public function handle(SyncFanOutService $fanOut): int
    {
        if (! config('sync.enabled')) {
            return self::SUCCESS;
        }

        $sourceAccountIds = SyncPipeline::withoutGlobalScopes()
            ->where('enabled', true)
            ->pluck('source_connected_account_id')
            ->unique()
            ->all();

        if ($sourceAccountIds === []) {
            return self::SUCCESS;
        }

        $floor = Date::now()->subMinutes((int) config('sync.reconcile_lookback_minutes', 60));

        PostTarget::query()
            ->with([
                'account' => fn ($query) => $query->withoutGlobalScopes(),
                'post' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('posted_at')
            ->where('posted_at', '>=', $floor)
            ->whereIn('connected_account_id', $sourceAccountIds)
            ->whereHas('account', fn (Builder $query): Builder => $query
                ->whereNull('disabled_at')
                ->where('status', ConnectedAccountStatus::Active->value))
            ->each(function (PostTarget $target) use ($fanOut): void {
                $fanOut->fanOut($target); // idempotent via unique index
            });

        return self::SUCCESS;
    }
}

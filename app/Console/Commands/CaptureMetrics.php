<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\MetricsCaptureCadence;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class CaptureMetrics extends Command
{
    protected $signature = 'metrics:capture';

    protected $description = 'Fan out metric-capture jobs for due published targets and connected accounts.';

    public function handle(MetricsCaptureCadence $cadence, InstanceSettings $settings): int
    {
        if (! $settings->metricsEnabled()) {
            return self::SUCCESS;
        }

        $now = Date::now();

        if ($settings->postMetricsPollingEnabled()) {
            PostTarget::query()
                ->where('status', PostTargetStatus::Published->value)
                ->whereNotNull('remote_id')
                ->whereNotNull('posted_at')
                ->whereHas('account', fn ($query) => $query->whereNull('disabled_at'))
                ->where('posted_at', '>=', $now->copy()->subDays(30))
                ->where(fn ($q) => $q->whereNull('metrics_status')->orWhere('metrics_status', '!=', MetricsStatus::Unsupported->value))
                ->each(function (PostTarget $target) use ($cadence, $now): void {
                    if ($cadence->postTargetDue($target, $now)) {
                        CapturePostTargetMetrics::dispatch($target);
                    }
                });
        }

        if ($settings->accountMetricsPollingEnabled()) {
            ConnectedAccount::query()
                ->withoutGlobalScopes()
                ->enabled()
                ->where('status', ConnectedAccountStatus::Active->value)
                ->where(fn ($q) => $q->whereNull('metrics_status')->orWhere('metrics_status', '!=', MetricsStatus::Unsupported->value))
                ->each(function (ConnectedAccount $account) use ($cadence, $now): void {
                    if ($cadence->accountDue($account, $now)) {
                        CaptureAccountMetrics::dispatch($account);
                    }
                });
        }

        return self::SUCCESS;
    }
}

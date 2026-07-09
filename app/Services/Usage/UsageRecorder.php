<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Support\InstanceSettings;
use App\Support\UsagePricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UsageRecorder
{
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly UsagePricing $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        UsageCategory $category,
        string $operation,
        string $workspaceId,
        ?Platform $platform = null,
        int $quotaWeight = 1,
        bool $succeeded = true,
        array $meta = [],
    ): void {
        try {
            // Record when metering is explicitly enabled, or when workspace
            // subscriptions are active — the billing gate reads these counters
            // (e.g. the monthly X publish limit) and must not silently no-op on a
            // cloud instance that leaves the metering toggle off.
            if (! $this->settings->usageTrackingEnabled() && ! (bool) config('subscriptions.enabled')) {
                return;
            }

            DB::transaction(function () use ($category, $operation, $workspaceId, $platform, $quotaWeight, $succeeded, $meta): void {
                $costWeightMicrousd = $this->costWeightMicrousd($platform, $operation, $quotaWeight);

                UsageEvent::query()->create([
                    'workspace_id' => $workspaceId,
                    'category' => $category->value,
                    'operation' => $operation,
                    'platform' => $platform?->value,
                    'quota_weight' => $quotaWeight,
                    'cost_weight_microusd' => $costWeightMicrousd,
                    'succeeded' => $succeeded,
                    'meta' => $meta === [] ? null : $meta,
                    'occurred_at' => Date::now(),
                ]);

                if ($succeeded) {
                    $this->incrementCounter($category, $operation, $workspaceId, $platform, $quotaWeight, $costWeightMicrousd);
                }
            });
        } catch (Throwable $e) {
            // Metering must never break the caller (a publish/fetch), but a silent
            // failure means billed usage goes uncounted — report to the exception
            // handler (monitoring) in addition to the log line.
            report($e);
            Log::error('Usage recording failed.', ['message' => $e->getMessage()]);
        }
    }

    private function incrementCounter(
        UsageCategory $category,
        string $operation,
        string $workspaceId,
        ?Platform $platform,
        int $quotaWeight,
        int $costWeightMicrousd,
    ): void {
        $now = CarbonImmutable::instance(Date::now());

        $keys = [
            'workspace_id' => $workspaceId,
            'period_start' => $now->startOfMonth()->toDateString(),
            'category' => $category->value,
            'platform' => $platform !== null ? $platform->value : 'none',
            'operation' => $operation,
        ];

        // Ensure the row exists WITHOUT throwing on a concurrent create. insertOrIgnore
        // is portable (sqlite/MySQL/Postgres) and — unlike a caught unique violation —
        // never aborts the surrounding transaction on Postgres.
        UsagePeriodCounter::query()->insertOrIgnore([
            ...$keys,
            'id' => (string) Str::uuid(),
            'period_end' => $now->endOfMonth()->toDateString(),
            'event_count' => 0,
            'total_quota' => 0,
            'total_cost_microusd' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Atomic increment: compiles to `SET col = col + ?` on every driver.
        UsagePeriodCounter::query()->where($keys)->incrementEach(
            ['total_quota' => $quotaWeight, 'total_cost_microusd' => $costWeightMicrousd, 'event_count' => 1],
            ['updated_at' => $now],
        );
    }

    private function costWeightMicrousd(?Platform $platform, string $operation, int $quotaWeight): int
    {
        if ($platform === null) {
            return 0;
        }

        return $this->pricing->costWeightMicrousd($platform->value, $operation, $quotaWeight);
    }
}

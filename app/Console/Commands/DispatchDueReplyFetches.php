<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\PostTarget;
use App\Services\Engagement\ReplyFetchCadence;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class DispatchDueReplyFetches extends Command
{
    protected $signature = 'engagement:dispatch-due';

    protected $description = 'Fan out reply-fetch jobs for due published targets.';

    public function handle(InstanceSettings $settings, ReplyFetchCadence $cadence): int
    {
        if (! config('engagement.enabled') || ! $settings->engagementPollingEnabled()) {
            return self::SUCCESS;
        }

        $enabledPlatforms = array_values(array_filter(
            Platform::cases(),
            fn (Platform $platform): bool => $settings->engagementPollingEnabled($platform),
        ));

        if ($enabledPlatforms === []) {
            return self::SUCCESS;
        }

        $now = Date::now()->toImmutable();

        // Coarse SQL prefilter: admit anything that *could* be due — never fetched,
        // or not fetched within the finest possible interval. There is deliberately
        // no age cutoff, so old posts keep polling at the steady tail cadence;
        // ReplyFetchCadence::isDue() makes the precise per-post decision in PHP.
        $finestInterval = min(
            (int) collect((array) config('engagement.reply_refresh'))->min('interval_minutes'),
            (int) config('engagement.steady_interval_minutes', 1440),
        );
        $staleBefore = $now->subMinutes($finestInterval);

        PostTarget::query()
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('remote_id')
            ->whereNotNull('posted_at')
            ->whereIn('platform', array_map(fn (Platform $platform): string => $platform->value, $enabledPlatforms))
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->whereNull('reply_fetched_at')
                    ->orWhere('reply_fetched_at', '<=', $staleBefore);
            })
            ->whereHas('account', fn ($q) => $q->where('status', ConnectedAccountStatus::Active->value))
            ->each(function (PostTarget $target) use ($cadence, $now): void {
                if ($cadence->isDue($target, $now)) {
                    FetchPostTargetReplies::dispatch($target);
                }
            });

        return self::SUCCESS;
    }
}

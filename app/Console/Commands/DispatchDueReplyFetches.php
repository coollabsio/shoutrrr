<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchAccountReplies;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Engagement\Contracts\BatchEngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Engagement\ReplyFetchCadence;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class DispatchDueReplyFetches extends Command
{
    protected $signature = 'engagement:dispatch-due';

    protected $description = 'Fan out reply-fetch jobs for due published targets.';

    public function handle(InstanceSettings $settings, ReplyFetchCadence $cadence, EngagementConnectorRegistry $registry): int
    {
        if (! $settings->engagementEnabled() || ! $settings->engagementPollingEnabled()) {
            return self::SUCCESS;
        }

        $enabledPlatforms = array_values(array_filter(
            Platform::cases(),
            fn (Platform $platform): bool => $settings->engagementPollingEnabled($platform),
        ));

        if ($enabledPlatforms === []) {
            return self::SUCCESS;
        }

        // Platforms whose connector can fetch a whole account's replies in one
        // batched call are dispatched per-account; the rest stay per-target.
        $batchableValues = array_map(
            fn (Platform $platform): string => $platform->value,
            array_filter($enabledPlatforms, fn (Platform $platform): bool => $registry->for($platform) instanceof BatchEngagementConnector),
        );

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

        /** @var array<string, true> $batchAccountIds */
        $batchAccountIds = [];

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
            ->whereHas('account', fn ($q) => $q
                ->whereNull('disabled_at')
                ->where('status', ConnectedAccountStatus::Active->value)
                ->where(fn ($q) => $q
                    ->whereNull('engagement_rate_limited_until')
                    ->orWhere('engagement_rate_limited_until', '<=', $now)))
            ->each(function (PostTarget $target) use ($cadence, $now, $batchableValues, &$batchAccountIds): void {
                if (! $cadence->isDue($target, $now)) {
                    return;
                }

                if (in_array($target->platform->value, $batchableValues, true)) {
                    // Collapse an account's due posts into one batched job.
                    $batchAccountIds[$target->connected_account_id] = true;

                    return;
                }

                FetchPostTargetReplies::dispatch($target);
            });

        ConnectedAccount::withoutGlobalScopes()
            ->whereIn('id', array_keys($batchAccountIds))
            ->each(fn (ConnectedAccount $account) => FetchAccountReplies::dispatch($account));

        return self::SUCCESS;
    }
}

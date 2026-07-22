<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\RepostPostTarget;
use App\Models\PostTarget;
use App\Services\Repost\RepostEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class DispatchDueReposts extends Command
{
    protected $signature = 'posts:dispatch-due-reposts';

    protected $description = 'Fan out auto-repost jobs for due, well-performing published targets.';

    public function handle(RepostEligibility $eligibility): int
    {
        if (! config('repost.enabled')) {
            return self::SUCCESS;
        }

        $now = Date::now()->toImmutable();

        $supportedValues = array_map(
            fn (Platform $platform): string => $platform->value,
            array_values(array_filter(Platform::cases(), fn (Platform $platform): bool => $platform->supportsRepost())),
        );

        $backfillFloor = $now->subDays((int) config('repost.max_backfill_days', 30));

        // Coarse SQL prefilter; RepostEligibility::shouldRepost() makes the precise
        // per-target decision (timing + performance gate + per-post override) in PHP.
        PostTarget::query()
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('remote_id')
            ->whereNotNull('posted_at')
            ->whereNull('reposted_at')
            ->whereIn('platform', $supportedValues)
            ->where('posted_at', '>=', $backfillFloor)
            ->whereHas('account', fn ($query) => $query
                ->whereNull('disabled_at')
                ->where('status', ConnectedAccountStatus::Active->value))
            ->each(function (PostTarget $target) use ($eligibility, $now): void {
                if ($eligibility->shouldRepost($target, $now)) {
                    RepostPostTarget::dispatch($target);
                }
            });

        return self::SUCCESS;
    }
}

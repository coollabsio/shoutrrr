<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class DispatchDueReplyFetches extends Command
{
    protected $signature = 'engagement:dispatch-due';

    protected $description = 'Fan out reply-fetch jobs for recently-published targets.';

    public function handle(InstanceSettings $settings): int
    {
        if (! config('engagement.enabled')) {
            return self::SUCCESS;
        }

        $cutoff = Date::now()->subDays((int) config('engagement.window_days', 14));

        PostTarget::query()
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('remote_id')
            ->whereNotNull('posted_at')
            ->where('posted_at', '>=', $cutoff)
            ->where(function ($query) use ($settings): void {
                foreach (Platform::cases() as $platform) {
                    $query->orWhere(function ($query) use ($settings, $platform): void {
                        $interval = Date::now()->subMinutes($settings->engagementPollIntervalMinutes($platform));

                        $query
                            ->where('platform', $platform->value)
                            ->where(function ($query) use ($interval): void {
                                $query
                                    ->whereNull('reply_fetched_at')
                                    ->orWhere('reply_fetched_at', '<=', $interval);
                            });
                    });
                }
            })
            ->whereHas('account', fn ($q) => $q->where('status', ConnectedAccountStatus::Active->value))
            ->each(fn (PostTarget $target) => FetchPostTargetReplies::dispatch($target));

        return self::SUCCESS;
    }
}

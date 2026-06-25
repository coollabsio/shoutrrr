<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\PostTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class DispatchDueReplyFetches extends Command
{
    protected $signature = 'engagement:dispatch-due';

    protected $description = 'Fan out reply-fetch jobs for recently-published targets.';

    public function handle(): int
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
            ->whereHas('account', fn ($q) => $q->where('status', ConnectedAccountStatus::Active->value))
            ->each(fn (PostTarget $target) => FetchPostTargetReplies::dispatch($target));

        return self::SUCCESS;
    }
}

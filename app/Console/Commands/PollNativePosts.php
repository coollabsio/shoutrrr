<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Jobs\IngestNativePosts;
use App\Models\ConnectedAccountNativeWatch;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Console\Command;

class PollNativePosts extends Command
{
    protected $signature = 'sync:poll-native';

    protected $description = 'Poll tracked accounts for native posts and ingest new ones as External.';

    public function handle(WorkspaceSubscriptionGate $gate): int
    {
        if (! config('sync.enabled')) {
            return self::SUCCESS;
        }

        ConnectedAccountNativeWatch::query()
            ->with([
                'account' => fn ($q) => $q->withoutGlobalScopes(),
                'workspace' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->each(function (ConnectedAccountNativeWatch $watch) use ($gate): void {
                $account = $watch->account;
                if ($account === null
                    || $account->isDisabled()
                    || $account->status !== ConnectedAccountStatus::Active
                    || ! $account->platform->supportsNativeRead()) {
                    return;
                }
                // Skip X polling when the workspace has exhausted its X API budget.
                if ($account->platform === Platform::X
                    && $watch->workspace !== null
                    && ! $gate->canPublishX($watch->workspace)) {
                    return;
                }

                IngestNativePosts::dispatch($account);
            });

        return self::SUCCESS;
    }
}

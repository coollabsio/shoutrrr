<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Jobs\SyncExternalAccountPosts;
use App\Models\ConnectedAccount;
use Illuminate\Console\Command;

class SyncExternalPosts extends Command
{
    protected $signature = 'external-posts:sync';

    protected $description = 'Sync posts created directly on connected platforms into Shoutrrr.';

    public function handle(): int
    {
        ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->where('platform', Platform::X->value)
            ->where('status', ConnectedAccountStatus::Active->value)
            ->where('sync_external_posts', true)
            ->each(function (ConnectedAccount $account): void {
                SyncExternalAccountPosts::dispatch($account);
            });

        return self::SUCCESS;
    }
}

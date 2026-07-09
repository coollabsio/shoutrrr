<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Exceptions\TokenRefreshException;
use App\Models\ConnectedAccount;
use App\Services\ExternalPosts\XExternalPostImporter;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class SyncExternalAccountPosts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public ConnectedAccount $account) {}

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('external-posts-x')];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(TokenManager $tokens, XExternalPostImporter $importer): void
    {
        $account = $this->account->newQueryWithoutScopes()->find($this->account->id);

        if (
            $account === null
            || $account->platform !== Platform::X
            || ! $account->sync_external_posts
            || $account->status !== ConnectedAccountStatus::Active
        ) {
            return;
        }

        try {
            $credentials = $tokens->fresh($account);
        } catch (TokenRefreshException) {
            return;
        }

        $importer->import($account, $credentials);
    }
}

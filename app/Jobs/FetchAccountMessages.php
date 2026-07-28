<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EngagementStatus;
use App\Exceptions\TokenRefreshException;
use App\Jobs\Concerns\ReportsReplyFetch;
use App\Jobs\Contracts\ReleasableJob;
use App\Jobs\Middleware\ThrottlesMessageFetch;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\MessageConnectorRegistry;
use App\Services\Messaging\MessagePersister;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches an account's direct-message conversations and persists new/changed
 * ones. Mirrors FetchAccountReplies' shape (unique-per-account, throttle
 * middleware, rate-limit parking) but for the unified DM inbox instead of the
 * engagement inbox.
 */
class FetchAccountMessages implements ReleasableJob, ShouldBeUnique, ShouldQueue
{
    use Queueable, ReportsReplyFetch;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

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
        return [new ThrottlesMessageFetch($this->account->id)];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        MessageConnectorRegistry $registry,
        TokenManager $tokens,
        MessagePersister $persister,
        InstanceSettings $settings,
    ): void {
        if (! $settings->messagesEnabled()) {
            return;
        }

        // Reload without the workspace scope: this job runs with no workspace
        // context, so a scoped fresh() would resolve to null.
        $account = ConnectedAccount::withoutGlobalScopes()->whereKey($this->account->getKey())->first();

        if ($account === null || $account->isDisabled() || ! $account->canReceiveDirectMessages()) {
            return;
        }

        $connector = $registry->for($account->platform);

        try {
            $credentials = $tokens->fresh($account);
        } catch (TokenRefreshException) {
            $this->logFetchOutcome($account->platform->value, $account->id, 'dm', 'token_refresh_failed');

            return;
        }

        $since = Conversation::withoutGlobalScopes()
            ->where('connected_account_id', $account->id)
            ->max('last_synced_at');

        $result = $connector->fetchConversations(
            $account,
            $credentials,
            $since !== null ? CarbonImmutable::parse($since) : null,
        );

        if ($result->status === EngagementStatus::RateLimited) {
            $this->logFetchOutcome($account->platform->value, $account->id, 'dm', 'rate_limited', 0, $result->retryAfterSeconds);
            $this->release($this->parkForMessageRateLimit($account, $result->retryAfterSeconds));

            return;
        }

        if (! $result->isOk()) {
            $this->logFetchOutcome($account->platform->value, $account->id, 'dm', $result->status->value);

            return;
        }

        $inserted = $persister->persist($account, $result->conversations);
        $this->logFetchOutcome($account->platform->value, $account->id, 'dm', 'ok', $inserted);
    }
}

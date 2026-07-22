<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Dto\Repost\RepostContext;
use App\Exceptions\TokenRefreshException;
use App\Models\PostTarget;
use App\Services\Publishing\TokenManager;
use App\Services\Repost\RepostConnectorRegistry;
use App\Services\Repost\RepostEligibility;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

class RepostPostTarget implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** Hold the uniqueness lock at most this long so a stuck worker can't block the next tick. */
    public int $uniqueFor = 300;

    public function __construct(public PostTarget $target) {}

    /** One in-flight repost per target: overlapping ticks must not double-boost. */
    public function uniqueId(): string
    {
        return $this->target->id;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(RepostConnectorRegistry $registry, TokenManager $tokens, RepostEligibility $eligibility): void
    {
        if (! config('repost.enabled')) {
            return;
        }

        $target = $this->target->fresh();

        if ($target === null || $target->reposted_at !== null) {
            return;
        }

        $account = $target->account()->withoutGlobalScopes()->first();

        if ($account === null || $account->isDisabled() || ! $target->platform->supportsRepost()) {
            return;
        }

        // Re-check at run time — state (metrics, override, config) may have changed since dispatch.
        if (! $eligibility->shouldRepost($target, Date::now()->toImmutable())) {
            return;
        }

        try {
            $credentials = $tokens->fresh($account);
        } catch (TokenRefreshException) {
            return; // transient; a later tick re-dispatches
        }

        $result = $registry->for($target->platform)->repost(new RepostContext($target, $account, $credentials));

        if ($result->isSuccessful()) {
            $target->forceFill([
                'reposted_at' => Date::now(),
                'repost_remote_id' => $result->remoteIds[0] ?? null,
            ])->save();

            return;
        }

        // Hard failure: mark attempted (remote_id stays null) so we don't retry forever.
        // Retryable failures fall through and are re-dispatched on the next scheduler tick.
        if ($result->errorKind !== null && ! $result->errorKind->isRetryable()) {
            $target->forceFill(['reposted_at' => Date::now()])->save();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use App\Exceptions\ReplyMayHavePosted;
use App\Exceptions\TokenRefreshException;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Notifications\ReplyFailedNotification;
use App\Services\Engagement\Contracts\PublishesReplyContainers;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class SendReply implements ShouldQueue
{
    use Queueable;

    public const int CONTAINER_CHECK_DELAY_SECONDS = 3;

    public const int MAX_CONTAINER_CHECKS = 20;

    /**
     * A rate-limit release still counts as an attempt, so a reply waiting for its
     * turn would fail after a few releases. Retry by time, and stop on real errors.
     */
    public int $maxExceptions = 3;

    public int $timeout = 300;

    /**
     * A processing container comes back as a follow-up job, so the wait never
     * holds a queue worker. `$containerId` keeps it from creating a second one.
     *
     * @param  list<string>  $mediaIds
     */
    public function __construct(
        public string $ourRowId,
        public string $parentReplyId,
        public array $mediaIds,
        public string $text,
        public Platform $platform,
        public ?string $containerId = null,
        public int $containerChecks = 0,
    ) {}

    /**
     * Throttle outbound replies on the same per-platform limiter as the fetch job,
     * so a burst of replies can't trip the platform's own rate limits. A follow-up
     * check is not a new send, and would otherwise spend the limit on status reads.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->containerId === null ? [new RateLimited("engagement-{$this->platform->value}")] : [];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(EngagementConnectorRegistry $registry, TokenManager $tokens): void
    {
        $ourRow = PostTargetReply::withoutGlobalScopes()->find($this->ourRowId);
        $parent = PostTargetReply::withoutGlobalScopes()->find($this->parentReplyId);

        if ($ourRow === null || $parent === null) {
            return;
        }

        // A prior attempt already delivered to the platform: never re-post on retry.
        if ($ourRow->send_status === SendStatus::Sent) {
            return;
        }

        $account = $parent->target?->account()->withoutGlobalScopes()->first();

        if ($account === null || $account->isDisabled()) {
            $this->failRow($ourRow);

            return;
        }

        try {
            $credentials = in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn, Platform::Threads], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            $this->failRow($ourRow);

            return;
        }

        $media = array_values(PostMedia::withoutGlobalScopes()->whereIn('id', $this->mediaIds)->get()->all());

        $connector = $registry->for($parent->platform);
        $result = $this->containerId !== null && $connector instanceof PublishesReplyContainers
            ? $connector->publishContainer($account, $this->containerId, $credentials)
            : $connector->postReply($account, $parent, $this->text, $credentials, $media);

        if ($result->processingContainerId !== null && $this->containerChecks < self::MAX_CONTAINER_CHECKS) {
            self::dispatch(
                $this->ourRowId,
                $this->parentReplyId,
                $this->mediaIds,
                $this->text,
                $this->platform,
                $result->processingContainerId,
                $this->containerChecks + 1,
            )->delay(self::CONTAINER_CHECK_DELAY_SECONDS);

            return;
        }

        if (! $result->isOk()) {
            // The connector's own words — e.g. "LinkedIn comments cannot include
            // attachments." Without this the user only ever sees a generic
            // failure and cannot tell what to change.
            $this->failRow($ourRow, $result->message);

            return;
        }

        // The platform has the reply now: a retry from here would post it twice.
        try {
            $ourRow->forceFill([
                'remote_reply_id' => $result->remoteReplyId,
                'remote_cid' => $result->remoteCid,
                'send_status' => SendStatus::Sent->value,
            ])->save();

            $parent->forceFill([
                'status' => ReplyStatus::Responded->value,
                'our_reply_remote_id' => $result->remoteReplyId,
            ])->save();
        } catch (Throwable $e) {
            $this->fail(new ReplyMayHavePosted($e));
        }
    }

    /**
     * Runs after the queue exhausts retries (or on an explicitly released failure).
     * Guards against the outgoing row being left stuck on `sending` forever when an
     * uncaught exception escapes handle(): mark it failed and notify the author.
     */
    public function failed(Throwable $e): void
    {
        $ourRow = PostTargetReply::withoutGlobalScopes()->find($this->ourRowId);

        // Don't report failure (or re-notify) for a reply that actually went out
        // — handle() may have posted successfully and thrown only afterwards.
        if ($ourRow !== null && $ourRow->send_status !== SendStatus::Sent) {
            $this->failRow($ourRow, $e instanceof ReplyMayHavePosted ? ReplyMayHavePosted::USER_MESSAGE : null);
        }
    }

    private function failRow(PostTargetReply $ourRow, ?string $reason = null): void
    {
        $ourRow->forceFill(['send_status' => SendStatus::Failed->value, 'send_error' => $reason])->save();

        $author = $ourRow->target?->post()->withoutGlobalScopes()->first()?->author()->first();
        $parent = PostTargetReply::withoutGlobalScopes()->find($this->parentReplyId);

        if ($author !== null && $parent !== null) {
            $author->notify(new ReplyFailedNotification($parent, $reason));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Exceptions\TokenRefreshException;
use App\Exceptions\TransientTokenRefreshException;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\PostTargetAttempt;
use App\Notifications\AccountNeedsAttentionNotification;
use App\Notifications\PostPublishedNotification;
use App\Notifications\PublishFailedNotification;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\SegmentMediaResolver;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    private const int MAX_ATTEMPTS = 5;

    private const int MAX_MEDIA_POLLS = 40;

    /** Fallback delay (seconds) between transcode-status polls when the platform gives no hint. */
    private const int MEDIA_POLL_DELAY = 10;

    /**
     * Each publish is its own retry loop (self-dispatched delayed jobs), so the queue
     * worker must not also retry — `tries=1` keeps a transient throw from amplifying
     * into duplicate posts. Combined with the terminal-status guard in handle().
     */
    public int $tries = 1;

    /**
     * Generous so a large video upload to a platform (streamed inside the first run)
     * can finish. MUST stay below the queue connection's `retry_after` (see config/queue.php,
     * default 1200) or a slow run would be released to a second worker and double-post.
     */
    public int $timeout = 900;

    private const array TERMINAL = [
        PostTargetStatus::Published,
        PostTargetStatus::Skipped,
        PostTargetStatus::Deleting,
        PostTargetStatus::Deleted,
    ];

    public function __construct(public PostTarget $target) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return $this->target->platform === Platform::GoogleBusinessProfile
            ? [new RateLimited('google-business-profile')]
            : [];
    }

    public function handle(
        PublishConnectorRegistry $registry,
        TokenManager $tokens,
        PostStatusRollup $rollup,
        BackoffSchedule $backoff,
        ?WorkspaceSubscriptionGate $subscriptions = null,
        ?InstanceSettings $settings = null,
    ): void {
        $subscriptions ??= app(WorkspaceSubscriptionGate::class);
        $settings ??= app(InstanceSettings::class);
        $target = $this->target->fresh() ?? $this->target;
        $this->target = $target;

        // Guard against a stale delayed retry or a double dispatch firing after the
        // target already reached a terminal state: doing nothing keeps it a no-op.
        if (in_array($target->status, self::TERMINAL, true)) {
            return;
        }

        if (! $settings->platformAvailable($target->platform)) {
            $target->forceFill([
                'status' => PostTargetStatus::Skipped->value,
                'error_kind' => null,
                'error_message' => "{$target->platform->label()} is disabled on this instance.",
                'next_attempt_at' => null,
            ])->save();

            $rollup->recompute($target->post()->firstOrFail());

            return;
        }

        if ($target->account()->first()?->isDisabled()) {
            $target->forceFill([
                'status' => PostTargetStatus::Skipped->value,
                'error_kind' => null,
                'error_message' => 'This account is disabled in the workspace.',
                'next_attempt_at' => null,
            ])->save();

            $rollup->recompute($target->post()->firstOrFail());

            return;
        }

        $attempt = DB::transaction(function () use ($target): PostTargetAttempt {
            $idempotencyKey = $target->idempotency_key ?? (string) Str::uuid();
            $remoteMetadata = $target->remote_metadata ?? [];
            if ($target->platform === Platform::GoogleBusinessProfile && $target->remote_id === null && ! isset($remoteMetadata['create_intent'])) {
                $remoteMetadata['create_intent'] = [
                    'key' => $idempotencyKey,
                    'state' => 'creating',
                    'started_at' => Date::now()->toIso8601String(),
                ];
            }
            $target->forceFill([
                'status' => PostTargetStatus::Publishing->value,
                'attempts' => $target->attempts + 1,
                // Real duplicate-prevention relies on incremental remote_ids resume (spec §4.3)
                // plus the terminal-status guard above; idempotency_key is reserved for providers
                // that support an idempotency header (X/Bluesky/LinkedIn do not uniformly today).
                'idempotency_key' => $idempotencyKey,
                'remote_metadata' => $remoteMetadata,
            ])->save();

            return PostTargetAttempt::create([
                'post_target_id' => $target->id,
                'attempt_no' => $target->attempts,
                'status' => 'retrying',
                'started_at' => Date::now(),
            ]);
        });

        $account = $target->account()->firstOrFail();

        $workspace = $target->post()->firstOrFail()->workspace()->firstOrFail();

        if (! $subscriptions->canPublish($workspace)) {
            $result = PublishResult::failure(
                ErrorKind::BillingRequired,
                'An active Shoutrrr subscription is required to publish posts.',
            );
        } elseif ($target->platform === Platform::X && ! $subscriptions->canPublishX($workspace)) {
            $result = PublishResult::failure(
                ErrorKind::BillingRequired,
                $subscriptions->remainingXPosts($workspace) > 0
                    ? 'Monthly X API budget exceeded. Upgrade or wait for the next billing period.'
                    : 'Monthly X publishing quota exceeded. Upgrade or wait for the next billing period.',
            );
        } elseif ($account->status === ConnectedAccountStatus::NeedsAttention) {
            $result = PublishResult::failure(
                ErrorKind::AuthExpired,
                "{$account->platform->label()} account needs attention. Reconnect it before publishing.",
            );
        } else {
            try {
                $credentials = $tokens->fresh($account);
                $connector = $registry->for($target->platform);
                $result = $connector->publish($this->context($target, $credentials));

                // The proactive token sweep can rotate a still-valid access token
                // just after this job reads it. A resulting 401 means the request
                // was rejected before any side effect, so it is safe to force one
                // fresh credential exchange and retry the publish once (media that
                // did upload resumes from stored state) before declaring the
                // account needs attention.
                if ($result->errorKind === ErrorKind::AuthExpired) {
                    $credentials = $tokens->fresh($account, force: true);
                    $result = $connector->publish($this->context($target, $credentials));
                }
            } catch (TransientTokenRefreshException $e) {
                // A transient token-endpoint failure (429/5xx/timeout) is not a bad
                // credential — treat it as a retryable server error so the publish backs
                // off and retries instead of flipping the account to needs-attention.
                $result = PublishResult::failure(ErrorKind::ServerError, $e->getMessage());
            } catch (TokenRefreshException $e) {
                $result = PublishResult::failure($e->errorKind, $e->getMessage(), $e->httpStatus, retryAfter: $e->retryAfter);
            }
        }

        if ($result->isSuccessful()) {
            $this->onSuccess($target, $attempt, $result);
        } else {
            $this->onFailure($target, $attempt, $result, $backoff);
        }

        $rollup->recompute($target->post()->firstOrFail());
    }

    /**
     * Runs when the job fails — either an uncaught throw from handle() or, more
     * insidiously, an orphaned queue reservation: a worker that died mid-run
     * (deploy/OOM/crash) leaves its reserved message behind, the database queue
     * re-delivers it after `retry_after`, and because `tries=1` the redelivery is
     * rejected as "attempted too many times" BEFORE handle() (and its terminal-status
     * guard) can run. Rather than blindly bury the target as Failed, reconcile against
     * the segment ids already persisted mid-run (BlueskyPublishConnector saves each
     * uri before sending the next), so a post that actually went out — or partly did —
     * is not lost:
     *   - all segments posted  → mark Published (the run just died before recording it);
     *   - some segments posted → re-dispatch to resume the thread (bounded by MAX_ATTEMPTS);
     *   - nothing posted       → mark terminally Failed.
     *
     * A redelivery can land up to `retry_after` after the orphan was created, so the
     * target may already be terminal (a parallel retry finished it, the user deleted the
     * post, or the platform was frozen). Mirror handle()'s terminal guard first so we
     * never resurrect a Deleted post, re-notify a Published one, or clobber a Skipped one.
     */
    public function failed(Throwable $e): void
    {
        $target = $this->target->fresh() ?? $this->target;

        if (in_array($target->status, self::TERMINAL, true)) {
            return;
        }

        $segmentCount = count($target->sections ?? []);
        $postedCount = count($this->postedRemoteIds($target));

        if ($segmentCount > 0 && $postedCount >= $segmentCount) {
            $this->reconcilePublished($target);

            return;
        }

        if ($postedCount > 0 && $target->attempts < self::MAX_ATTEMPTS) {
            $this->resumePartial($target);

            return;
        }

        $this->markFailed($target, $e);
    }

    /**
     * The non-empty AT-URIs already persisted for this target, in segment order.
     *
     * @return list<string>
     */
    private function postedRemoteIds(PostTarget $target): array
    {
        return array_values(array_filter(
            $target->remote_ids ?? [],
            static fn (string $remoteId): bool => $remoteId !== '',
        ));
    }

    /**
     * A worker died after every segment was posted but before onSuccess recorded it.
     * Recover the target to Published so the author is not wrongly told it failed.
     */
    private function reconcilePublished(PostTarget $target): void
    {
        $remoteIds = $this->postedRemoteIds($target);

        $target->forceFill([
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $remoteIds[0] ?? $target->remote_id,
            'remote_ids' => $remoteIds,
            'posted_at' => $target->posted_at ?? Date::now(),
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        $this->closeOpenAttempt($target, 'published');

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());

        $this->notifyPublished($target);
    }

    /**
     * A worker died mid-thread. Re-dispatch so handle() resumes from the persisted
     * remote_ids (posting only the unsent segments) rather than abandoning a
     * half-published thread as Failed.
     */
    private function resumePartial(PostTarget $target): void
    {
        $this->closeOpenAttempt($target, 'retrying');

        $target->forceFill([
            'status' => PostTargetStatus::Publishing->value,
            'next_attempt_at' => Date::now(),
        ])->save();

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());

        self::dispatch($target->fresh());
    }

    private function markFailed(PostTarget $target, Throwable $e): void
    {
        $this->closeOpenAttempt($target, 'failed', Str::limit($e->getMessage(), 1000));

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_message' => Str::limit($e->getMessage(), 1000),
            'next_attempt_at' => null,
        ])->save();

        app(PostStatusRollup::class)->recompute($target->post()->firstOrFail());

        $this->notifyFailed($target, ErrorKind::Unknown);
    }

    /**
     * Close the currently-open attempt row (the one this dead run left unfinished).
     */
    private function closeOpenAttempt(PostTarget $target, string $status, ?string $errorMessage = null): void
    {
        $attempt = $target->attemptLogs()->whereNull('finished_at')->latest('id')->first();

        if ($attempt === null) {
            return;
        }

        $fields = ['status' => $status, 'finished_at' => Date::now()];

        if ($errorMessage !== null) {
            $fields['error_message'] = $errorMessage;
        }

        $attempt->forceFill($fields)->save();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function context(PostTarget $target, array $credentials): PublishContext
    {
        $post = $target->post()->firstOrFail();
        $media = array_values($post->media()->get()->all());

        $placements = array_values($target->placements()->get()
            ->map(fn (PostMediaPlacement $p): array => [
                'post_media_id' => $p->post_media_id,
                'segment_ref' => $p->segment_ref,
                'position' => $p->position,
            ])->all());

        $mediaBySection = app(SegmentMediaResolver::class)->resolve(
            sections: $target->sections,
            sectionSources: $target->section_sources ?? [],
            segmentBreaks: $target->segment_breaks ?? [],
            placements: $placements,
            allMedia: $media,
        );

        return new PublishContext(
            target: $target,
            segments: $target->sections,
            media: $media,
            account: $target->account()->firstOrFail(),
            credentials: $credentials,
            mediaBySection: $mediaBySection,
        );
    }

    /**
     * Notify the post author that a target published successfully.
     */
    public function notifyPublished(PostTarget $target): void
    {
        $author = $target->post()->firstOrFail()->author()->first();

        $author?->notify(new PostPublishedNotification($target));
    }

    /**
     * Notify the post author about a terminal failure. Auth-expiry routes to the
     * reconnect notification; everything else to the publish-failed notification.
     */
    public function notifyFailed(PostTarget $target, ErrorKind $kind): void
    {
        $post = $target->post()->firstOrFail();
        $author = $post->author()->first();

        if ($author === null) {
            return;
        }

        if ($kind === ErrorKind::AuthExpired) {
            $author->notify(new AccountNeedsAttentionNotification(
                $target->account()->firstOrFail(),
                $post->workspace_id,
            ));

            return;
        }

        $author->notify(new PublishFailedNotification($target));
    }

    private function onSuccess(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result): void
    {
        $remoteMetadata = [...($target->remote_metadata ?? []), ...($result->remoteMetadata ?? [])];
        unset($remoteMetadata['create_intent']);
        $target->forceFill([
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $result->remoteIds[0] ?? null,
            'remote_ids' => $result->remoteIds,
            'remote_metadata' => $remoteMetadata,
            'posted_at' => Date::now(),
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        $attempt->forceFill([
            'status' => 'published',
            'http_status' => $result->httpStatus,
            'finished_at' => Date::now(),
        ])->save();

        if (in_array($result->remoteMetadata['state'] ?? null, ['PROCESSING', 'SCHEDULED'], true)) {
            ReconcileGoogleBusinessProfileLocalPost::dispatch($target->fresh())->delay(30);
        }

        $this->notifyPublished($target);
    }

    private function onFailure(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result, BackoffSchedule $backoff): void
    {
        if ($target->platform === Platform::GoogleBusinessProfile && $result->mayHaveCreatedRemote && isset(($target->remote_metadata ?? [])['create_intent'])) {
            $metadata = $target->remote_metadata ?? [];
            $metadata['create_intent']['state'] = 'outcome_unknown';
            $metadata['create_intent']['outcome_unknown_at'] = Date::now()->toIso8601String();
            $target->forceFill(['remote_metadata' => $metadata])->save();
            $result = PublishResult::failure(ErrorKind::Unknown, 'Google Business Profile create outcome is unknown. Verify the Local Post before retrying.');
        }
        if (($result->errorKind ?? null) === ErrorKind::MediaProcessing) {
            $this->onMediaProcessing($target, $attempt, $result);

            return;
        }

        $kind = $result->errorKind ?? ErrorKind::Unknown;
        $canRetry = $kind->isRetryable() && $target->attempts < self::MAX_ATTEMPTS;

        $attempt->forceFill([
            'status' => $canRetry ? 'retrying' : 'failed',
            'error_kind' => $kind->value,
            'error_message' => $result->errorMessage,
            'http_status' => $result->httpStatus,
            'response_excerpt' => $result->responseExcerpt,
            'finished_at' => Date::now(),
        ])->save();

        if ($canRetry) {
            $delay = $result->retryAfter ?? $backoff->nextDelaySeconds($target->attempts);

            $target->forceFill([
                'status' => PostTargetStatus::Publishing->value,
                'error_kind' => $kind->value,
                'error_message' => $result->errorMessage,
                'next_attempt_at' => Date::now()->addSeconds($delay),
            ])->save();

            self::dispatch($target->fresh())->delay($delay);

            return;
        }

        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => $kind->value,
            'error_message' => $result->errorMessage,
            'next_attempt_at' => null,
        ])->save();

        if ($kind === ErrorKind::AuthExpired) {
            $target->account()->firstOrFail()->forceFill([
                'status' => ConnectedAccountStatus::NeedsAttention->value,
            ])->save();
        }

        $this->notifyFailed($target, $kind);
    }

    private function onMediaProcessing(PostTarget $target, PostTargetAttempt $attempt, PublishResult $result): void
    {
        $state = new MediaUploadState($target->media_upload_state);
        $polls = $state->incrementPolls();

        if ($polls > self::MAX_MEDIA_POLLS) {
            Log::warning('Video transcode poll timed out', [
                'post_target_id' => $target->id,
                'platform' => $target->platform->value,
                'polls' => $polls,
            ]);

            $attempt->forceFill([
                'status' => 'failed',
                'error_kind' => ErrorKind::ServerError->value,
                'error_message' => 'Video processing did not complete in time.',
                'finished_at' => Date::now(),
            ])->save();

            $target->forceFill([
                'status' => PostTargetStatus::Failed->value,
                'error_kind' => ErrorKind::ServerError->value,
                'error_message' => 'Video processing did not complete in time.',
                'media_upload_state' => $state->toArray(),
                'next_attempt_at' => null,
            ])->save();

            $this->notifyFailed($target, ErrorKind::ServerError);

            return;
        }

        // Honor the platform's suggested delay; otherwise poll on a tight fixed cadence
        // (not the publish backoff, whose 60s base is far too slow for transcode checks).
        $delay = $result->retryAfter ?? self::MEDIA_POLL_DELAY;

        $attempt->forceFill([
            'status' => 'retrying',
            'error_kind' => ErrorKind::MediaProcessing->value,
            'error_message' => $result->errorMessage,
            'finished_at' => Date::now(),
        ])->save();

        $target->forceFill([
            'status' => PostTargetStatus::Publishing->value,
            // Transcode polls must not exhaust the publish-failure budget, so neutralize
            // the attempts++ that handle() applied at the start of this run.
            'attempts' => max(0, $target->attempts - 1),
            'media_upload_state' => $state->toArray(),
            'next_attempt_at' => Date::now()->addSeconds($delay),
        ])->save();

        self::dispatch($target->fresh())->delay($delay);
    }
}

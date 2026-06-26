<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\SendStatus;
use App\Exceptions\TokenRefreshException;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Notifications\ReplyFailedNotification;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class SendReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  list<string>  $mediaIds
     */
    public function __construct(
        public string $ourRowId,
        public string $parentReplyId,
        public array $mediaIds,
        public string $text,
        public Platform $platform,
    ) {}

    /**
     * Throttle outbound replies on the same per-platform limiter as the fetch job,
     * so a burst of replies can't trip the platform's own rate limits.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited("engagement-{$this->platform->value}")];
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

        if ($account === null) {
            $this->failRow($ourRow);

            return;
        }

        try {
            $credentials = in_array($account->platform, [Platform::X, Platform::Bluesky, Platform::LinkedIn], true)
                ? $tokens->fresh($account)
                : [];
        } catch (TokenRefreshException) {
            $this->failRow($ourRow);

            return;
        }

        $media = array_values(PostMedia::withoutGlobalScopes()->whereIn('id', $this->mediaIds)->get()->all());

        $result = $registry->for($parent->platform)->postReply($account, $parent, $this->text, $credentials, $media);

        if (! $result->isOk()) {
            $this->failRow($ourRow);

            return;
        }

        $ourRow->forceFill([
            'remote_reply_id' => $result->remoteReplyId,
            'remote_cid' => $result->remoteCid,
            'send_status' => SendStatus::Sent->value,
        ])->save();

        $parent->forceFill([
            'status' => ReplyStatus::Responded->value,
            'our_reply_remote_id' => $result->remoteReplyId,
        ])->save();
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
            $this->failRow($ourRow);
        }
    }

    private function failRow(PostTargetReply $ourRow): void
    {
        $ourRow->forceFill(['send_status' => SendStatus::Failed->value])->save();

        $author = $ourRow->target?->post()->withoutGlobalScopes()->first()?->author()->first();
        $parent = PostTargetReply::withoutGlobalScopes()->find($this->parentReplyId);

        if ($author !== null && $parent !== null) {
            $author->notify(new ReplyFailedNotification($parent));
        }
    }
}

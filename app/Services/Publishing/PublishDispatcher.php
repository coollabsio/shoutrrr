<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;

class PublishDispatcher
{
    private const array TERMINAL = [
        PostTargetStatus::Published,
        PostTargetStatus::Skipped,
        PostTargetStatus::Deleting,
        PostTargetStatus::Deleted,
    ];

    public function __construct(
        private readonly PublishPrecheck $precheck,
        private readonly PostStatusRollup $rollup,
    ) {}

    /**
     * Fan out one publish job per non-terminal target, but first drop any target
     * the precheck would reject (empty, media-required, over-limit). Enforcing the
     * precheck HERE — the single choke point every publish path funnels through —
     * means a doomed target can never reach the platform API, whether it was
     * published from the composer, the API, the scheduler, or an MCP tool. The
     * interactive callers still precheck up front to return a 422 before flipping
     * the post to publishing; this is the backstop that covers the rest.
     */
    public function dispatchForPost(Post $post): void
    {
        $post->loadMissing(['targets.account', 'media']);

        $issuesByAccount = [];
        foreach ($this->precheck->blockingTargets($post) as $blocked) {
            $issuesByAccount[$blocked['connected_account_id']] = $blocked['issues'];
        }

        $marked = false;
        foreach ($post->targets as $target) {
            if (in_array($target->status, self::TERMINAL, true)) {
                continue;
            }

            $issues = $issuesByAccount[(string) $target->connected_account_id] ?? null;
            if ($issues !== null) {
                $this->markBlocked($target, $issues);
                $marked = true;

                continue;
            }

            PublishPostTarget::dispatch($target);
        }

        if ($marked) {
            $this->rollup->recompute($post);
        }
    }

    /**
     * Record a precheck-blocked target as a terminal validation failure instead of
     * dispatching a job the connector would only reject at the platform API.
     *
     * @param  list<string>  $issues
     */
    private function markBlocked(PostTarget $target, array $issues): void
    {
        $target->forceFill([
            'status' => PostTargetStatus::Failed->value,
            'error_kind' => ErrorKind::Validation->value,
            'error_message' => $this->precheck->describe($issues, $target->platform),
            'next_attempt_at' => null,
        ])->save();
    }
}

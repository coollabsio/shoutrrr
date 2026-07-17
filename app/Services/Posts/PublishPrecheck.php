<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\Post;
use App\Models\PostTarget;

class PublishPrecheck
{
    public function __construct(private readonly PostSplitter $splitter) {}

    /**
     * Targets whose stored content would be rejected by the platform. Reuses the
     * same validation the composer preview shows, so a doomed publish is stopped
     * before dispatch instead of failing per-target on the platform API.
     *
     * A target with neither text nor media is blocked as `empty` — there is
     * nothing to post, and the platform limit checks are meaningless on it.
     *
     * The media-required rule lives here rather than in
     * PostSplitter::validateSections() because split() calls that method with a
     * hardcoded media count of 0 (it runs before media is known), which would
     * report a false `media_required` on every Instagram draft.
     *
     * @return list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}>
     */
    public function blockingTargets(Post $post): array
    {
        $mediaCount = $post->media->count();

        /** @var list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}> $blocking */
        $blocking = [];

        foreach ($post->targets as $target) {
            /** @var PostTarget $target */
            $issues = $this->hasContent($target, $mediaCount)
                ? $this->targetIssues($target, $mediaCount)
                : ['empty'];

            if ($issues === []) {
                continue;
            }

            $blocking[] = [
                'connected_account_id' => (string) $target->connected_account_id,
                'handle' => $target->account?->handle,
                'platform' => $target->platform->value,
                'issues' => $issues,
            ];
        }

        return $blocking;
    }

    /**
     * Platform-limit issues for a target that has content, plus the
     * media-required rule the platform limits don't cover.
     *
     * @return list<string>
     */
    private function targetIssues(PostTarget $target, int $mediaCount): array
    {
        $issues = $this->splitter->validateSections(
            $target->sections,
            $target->platform,
            $mediaCount,
            $target->account?->maxTextLength(),
        );

        if ($mediaCount === 0 && $target->platform->requiresMedia()) {
            $issues[] = 'media_required';
        }

        return $issues;
    }

    /**
     * Whether a target has anything worth posting. Empty segments are stored as
     * a single blank section by PostSplitter, so a text-less target arrives here
     * as `['']` rather than `[]`.
     */
    private function hasContent(PostTarget $target, int $mediaCount): bool
    {
        if ($mediaCount > 0) {
            return true;
        }

        foreach ($target->sections as $section) {
            if (trim($section) !== '') {
                return true;
            }
        }

        return false;
    }
}

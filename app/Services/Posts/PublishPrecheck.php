<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\Post;
use App\Models\PostTarget;

class PublishPrecheck
{
    public function __construct(private readonly PostSplitter $splitter) {}

    /**
     * Targets whose stored sections would be rejected by the platform. Reuses the
     * same validation the composer preview shows, so a doomed publish is stopped
     * before dispatch instead of failing per-target on the platform API.
     *
     * @return list<array{connected_account_id: string, handle: ?string, platform: string, issues: list<string>}>
     */
    public function blockingTargets(Post $post): array
    {
        $mediaCount = $post->media->count();

        return $post->targets
            ->map(function (PostTarget $target) use ($mediaCount): ?array {
                $issues = $this->splitter->validateSections(
                    $target->sections,
                    $target->platform,
                    $mediaCount,
                    $target->account?->maxTextLength(),
                );

                if ($issues === []) {
                    return null;
                }

                return [
                    'connected_account_id' => (string) $target->connected_account_id,
                    'handle' => $target->account?->handle,
                    'platform' => $target->platform->value,
                    'issues' => $issues,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}

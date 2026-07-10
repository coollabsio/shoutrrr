<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;

class PublishDispatcher
{
    private const array TERMINAL = [
        PostTargetStatus::Published,
        PostTargetStatus::Skipped,
        PostTargetStatus::Deleting,
        PostTargetStatus::Deleted,
    ];

    public function dispatchForPost(Post $post): void
    {
        $post->targets()
            ->get()
            ->reject(fn (PostTarget $target): bool => in_array($target->status, self::TERMINAL, true))
            ->each(fn (PostTarget $target) => PublishPostTarget::dispatch($target));
    }
}

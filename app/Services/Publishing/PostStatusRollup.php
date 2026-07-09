<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use Illuminate\Support\Facades\Date;

class PostStatusRollup
{
    public function recompute(Post $post): void
    {
        $statuses = $post->targets()->pluck('status')
            ->map(fn (PostTargetStatus|string $value): PostTargetStatus => $value instanceof PostTargetStatus ? $value : PostTargetStatus::from($value));

        $hasInFlight = $statuses->contains(fn (PostTargetStatus $s): bool => in_array($s, [PostTargetStatus::Pending, PostTargetStatus::Publishing], true));
        $published = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Published)->count();
        $total = $statuses->count();

        $hasTargets = $total > 0;
        $allPublished = $published === $total;
        $anyPublished = $published > 0;

        $status = match (true) {
            $hasInFlight => PostStatus::Publishing,
            $hasTargets && $allPublished => PostStatus::Published,
            $hasTargets && $anyPublished => PostStatus::Partial, // some published, rest failed/skipped
            $hasTargets => PostStatus::Failed,                   // nothing published (all failed/skipped)
            default => PostStatus::Partial,                      // no targets — unchanged edge
        };

        $post->status = $status;

        if (in_array($status, [PostStatus::Published, PostStatus::Partial], true) && $post->published_at === null) {
            $post->published_at = Date::now();
        }

        $post->save();
    }
}

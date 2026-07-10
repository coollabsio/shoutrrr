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

        $total = $statuses->count();
        $hasInFlight = $statuses->contains(fn (PostTargetStatus $s): bool => in_array($s, [PostTargetStatus::Pending, PostTargetStatus::Publishing], true));
        $published = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Published)->count();
        $failed = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Failed)->count();
        $skipped = $statuses->filter(fn (PostTargetStatus $s): bool => $s === PostTargetStatus::Skipped)->count();

        $allPublished = $total > 0 && $published === $total;
        $noneReached = $total > 0 && $published === 0 && ($failed + $skipped) === $total;

        $status = match (true) {
            $hasInFlight => PostStatus::Publishing,
            $allPublished => PostStatus::Published,
            $noneReached => PostStatus::Failed,
            default => PostStatus::Partial,
        };

        $post->status = $status;

        if (in_array($status, [PostStatus::Published, PostStatus::Partial], true) && $post->published_at === null) {
            $post->published_at = Date::now();
        }

        $post->save();
    }
}

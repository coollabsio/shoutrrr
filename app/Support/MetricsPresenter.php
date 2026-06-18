<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MetricsStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;

final class MetricsPresenter
{
    /**
     * @return array{supported: bool, captured_at: string|null, totals: array{likes: int, comments: int, reposts: int}, targets: list<array{id: string, platform: string, handle: string|null, display_name: string|null, avatar_url: string|null, status: string|null, likes: int, comments: int, reposts: int, impressions: int|null, captured_at: string|null, series: array<int, array{at: string, likes: int, comments: int, reposts: int, impressions: int|null}>}>}
     */
    public static function forPost(Post $post): array
    {
        $post->loadMissing(['targets.account', 'targets.metrics']);

        $targets = $post->targets
            ->filter(fn (PostTarget $t): bool => $t->status === PostTargetStatus::Published)
            ->values();

        $totals = ['likes' => 0, 'comments' => 0, 'reposts' => 0];
        $capturedAt = null;
        $supported = false;
        $rows = [];

        foreach ($targets as $target) {
            $isOk = $target->metrics_status === MetricsStatus::Ok;
            $supported = $supported || $target->metrics_status !== MetricsStatus::Unsupported;

            if ($isOk) {
                $totals['likes'] += $target->likes;
                $totals['comments'] += $target->comments;
                $totals['reposts'] += $target->reposts;

                $at = $target->metrics_captured_at?->toIso8601String();
                if ($at !== null && ($capturedAt === null || $at > $capturedAt)) {
                    $capturedAt = $at;
                }
            }

            $rows[] = [
                'id' => $target->id,
                'platform' => $target->platform->value,
                'handle' => $target->account?->handle,
                'display_name' => $target->account?->display_name,
                'avatar_url' => $target->account?->avatar_url,
                'status' => $target->metrics_status?->value,
                'likes' => $target->likes,
                'comments' => $target->comments,
                'reposts' => $target->reposts,
                'impressions' => $target->impressions,
                'captured_at' => $target->metrics_captured_at?->toIso8601String(),
                'series' => $target->metrics
                    ->sortBy('captured_at')
                    ->map(fn (PostTargetMetric $m): array => [
                        'at' => $m->captured_at->toIso8601String(),
                        'likes' => $m->likes,
                        'comments' => $m->comments,
                        'reposts' => $m->reposts,
                        'impressions' => $m->impressions,
                    ])->values()->all(),
            ];
        }

        return [
            'supported' => $supported,
            'captured_at' => $capturedAt,
            'totals' => $totals,
            'targets' => $rows,
        ];
    }
}

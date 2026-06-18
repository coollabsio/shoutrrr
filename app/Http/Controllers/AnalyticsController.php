<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MetricsStatus;
use App\Enums\PostStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('viewAny', Post::class), 403);

        $days = max(7, min(365, (int) $request->integer('days', 90)));
        $from = Date::now()->subDays($days);

        $accounts = ConnectedAccount::query()
            ->with(['metrics' => fn ($q) => $q->where('captured_at', '>=', $from)->orderBy('captured_at')])
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->metrics_status?->value,
                'latest_followers' => $account->metrics->last()?->followers,
                'series' => $account->metrics->map(fn (AccountMetric $m): array => [
                    'at' => $m->captured_at->toIso8601String(),
                    'followers' => $m->followers,
                    'following' => $m->following,
                ])->values()->all(),
            ])->all();

        $posts = Post::query()
            ->with('targets:id,post_id,platform,likes,comments,reposts,metrics_status')
            ->whereIn('status', [PostStatus::Published->value, PostStatus::Partial->value])
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $from)
            ->orderBy('published_at')
            ->get();

        $markers = $posts->map(fn (Post $post): array => [
            'id' => $post->id,
            'title' => $this->resolveTitle($post),
            'published_at' => $post->published_at?->toIso8601String(),
            'platforms' => $post->targets->pluck('platform')->map(fn ($p): string => $p->value)->unique()->values()->all(),
        ])->all();

        $ranked = $posts
            ->filter(fn (Post $post): bool => $post->targets->contains(
                fn (PostTarget $t): bool => $t->metrics_status === MetricsStatus::Ok,
            ))
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'title' => $this->resolveTitle($post),
                'published_at' => $post->published_at?->toIso8601String(),
                'platforms' => $post->targets->pluck('platform')->map(fn ($p): string => $p->value)->unique()->values()->all(),
                'engagement' => (int) $post->targets->sum(fn (PostTarget $t): int => $t->likes + $t->comments + $t->reposts),
            ])
            ->sortByDesc('engagement')
            ->values();

        $comparisonTop = $ranked->count() < 10
            ? $ranked->values()->all()
            : $ranked->take(5)->values()->all();

        $comparisonBottom = $ranked->count() < 10
            ? []
            : $ranked->reverse()->take(5)->values()->all();

        return Inertia::render('analytics/index', [
            'accounts' => $accounts,
            'posts' => $markers,
            'comparison' => [
                'top' => $comparisonTop,
                'bottom' => $comparisonBottom,
            ],
            'rangeDays' => $days,
        ]);
    }

    private function resolveTitle(Post $post): string
    {
        $first = trim((string) Str::of($post->base_text)->explode("\n")->first());

        return Str::limit($first, 60) ?: 'Untitled post';
    }
}

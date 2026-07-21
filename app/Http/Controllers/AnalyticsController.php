<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, InstanceSettings $settings): Response
    {
        abort_unless($request->user()->can('viewAny', Post::class), 403);

        $days = max(7, min(365, (int) $request->integer('days', 90)));

        return Inertia::render('analytics/index', [
            ...$this->buildPayload($days),
            'rangeDays' => $days,
            'polling' => [
                'post_metrics_enabled' => collect(Platform::cases())
                    ->mapWithKeys(fn (Platform $platform): array => [
                        $platform->value => $settings->postMetricsPollingEnabled($platform),
                    ])
                    ->all(),
                'account_metrics_enabled' => collect(Platform::cases())
                    ->mapWithKeys(fn (Platform $platform): array => [
                        $platform->value => $settings->accountMetricsPollingEnabled($platform),
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array{accounts: array<int, array<string, mixed>>, posts: array<int, array<string, mixed>>, summary: array<string, mixed>, comparison: array{top: array<int, array<string, mixed>>, bottom: array<int, array<string, mixed>>}}
     */
    private function buildPayload(int $days): array
    {
        $from = Date::now()->subDays($days);
        $previousFrom = Date::now()->subDays($days * 2);

        // Discord webhooks have no follower/member metrics — omit them from the
        // follower growth chart and account cards entirely.
        $accounts = ConnectedAccount::query()
            ->where('platform', '!=', Platform::Discord->value)
            ->with(['metrics' => fn ($q) => $q
                ->where('captured_at', '>=', $from)
                ->orderBy('captured_at')
                // Drop the per-row `raw` API-response JSON blob — the series only
                // needs follower/following counts.
                ->select(['id', 'connected_account_id', 'captured_at', 'followers', 'following'])])
            ->get()
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->metrics_status?->value,
                'latest_followers' => $account->metrics->last()?->followers,
                'followers_delta' => $this->followerDelta($account->metrics),
                'series' => $this->downsampleDaily($account->metrics),
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

        return [
            'accounts' => $accounts,
            'posts' => $markers,
            'summary' => $this->buildSummary($accounts, $posts, $ranked, $previousFrom, $from),
            'comparison' => [
                'top' => $comparisonTop,
                'bottom' => $comparisonBottom,
            ],
        ];
    }

    /**
     * The headline numbers — total followers, engagement, and posts published —
     * each with a change vs the previous equal-length window. Deltas are null
     * when there's no honest baseline to compare against.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @return array<string, mixed>
     */
    private function buildSummary(array $accounts, Collection $posts, Collection $ranked, CarbonInterface $previousFrom, CarbonInterface $from): array
    {
        $accountsCollection = collect($accounts);

        $totalFollowers = (int) $accountsCollection->sum(fn (array $a): int => (int) ($a['latest_followers'] ?? 0));
        $trackedDeltas = $accountsCollection->pluck('followers_delta')->filter(fn ($d): bool => $d !== null);
        $followersDelta = $trackedDeltas->isEmpty() ? null : (int) $trackedDeltas->sum();

        $totalEngagement = (int) $ranked->sum('engagement');
        $postsCount = $posts->count();

        // Previous window — only used as a baseline for the delta chips.
        $previousPosts = Post::query()
            ->with('targets:id,post_id,platform,likes,comments,reposts,metrics_status')
            ->whereIn('status', [PostStatus::Published->value, PostStatus::Partial->value])
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $previousFrom)
            ->where('published_at', '<', $from)
            ->get();

        $hasBaseline = $previousPosts->isNotEmpty();
        $previousEngagement = (int) $previousPosts
            ->filter(fn (Post $post): bool => $post->targets->contains(
                fn (PostTarget $t): bool => $t->metrics_status === MetricsStatus::Ok,
            ))
            ->sum(fn (Post $post): int => (int) $post->targets->sum(fn (PostTarget $t): int => $t->likes + $t->comments + $t->reposts));

        return [
            'account_count' => $accountsCollection->count(),
            'followers' => [
                'value' => $totalFollowers,
                'delta' => $followersDelta,
            ],
            'engagement' => [
                'value' => $totalEngagement,
                'delta' => $hasBaseline ? $totalEngagement - $previousEngagement : null,
            ],
            'posts' => [
                'value' => $postsCount,
                'delta' => $hasBaseline ? $postsCount - $previousPosts->count() : null,
            ],
        ];
    }

    /**
     * Change in followers across the window: latest reading minus the earliest.
     * Null unless there are at least two comparable readings.
     *
     * @param  Collection<int, AccountMetric>  $metrics
     */
    private function followerDelta($metrics): ?int
    {
        if ($metrics->count() < 2) {
            return null;
        }

        $first = $metrics->first()?->followers;
        $last = $metrics->last()?->followers;

        if ($first === null || $last === null) {
            return null;
        }

        return $last - $first;
    }

    /**
     * Collapse a chronologically-ordered metric collection to one point per day
     * (the last reading of each day), bounding the series sent to the client
     * regardless of how often metrics are captured.
     *
     * @param  Collection<int, AccountMetric>  $metrics
     * @return array<int, array{at: string, followers: int|null, following: int|null}>
     */
    private function downsampleDaily($metrics): array
    {
        return $metrics
            ->groupBy(fn (AccountMetric $m): string => $m->captured_at->toDateString())
            ->map(fn ($dayMetrics): AccountMetric => $dayMetrics->last())
            ->map(fn (AccountMetric $m): array => [
                'at' => $m->captured_at->toIso8601String(),
                'followers' => $m->followers,
                'following' => $m->following,
            ])
            ->values()
            ->all();
    }

    private function resolveTitle(Post $post): string
    {
        $first = trim((string) Str::of($post->base_text)->explode("\n")->first());

        return Str::limit($first, 60) ?: 'Untitled post';
    }
}

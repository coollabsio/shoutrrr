<?php

declare(strict_types=1);

namespace App\Services\Repost;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RepostEligibility
{
    /**
     * Per-account+platform baseline cohorts, memoized for the lifetime of one
     * dispatch cycle so a batch of an account's targets shares a single baseline
     * query instead of issuing one per target.
     *
     * @var array<string, Collection<int, PostTarget>>
     */
    private array $baselineCache = [];

    public function __construct(private readonly EngagementScore $score) {}

    /**
     * Full decision for a single target. Loads the account and post without global
     * scopes (scheduler runs outside any workspace context).
     */
    public function shouldRepost(PostTarget $target, CarbonImmutable $now): bool
    {
        if ($target->reposted_at !== null) {
            return false;
        }

        if (! $target->platform->supportsRepost()) {
            return false;
        }

        // Prefer eager-loaded relations (the dispatcher preloads them) and fall
        // back to a scope-free lazy load so a standalone call still works outside
        // any workspace context.
        $account = $target->relationLoaded('account')
            ? $target->account
            : $target->account()->withoutGlobalScopes()->first();

        if ($account === null || $account->isDisabled()) {
            return false;
        }

        $config = $account->autoRepostConfig();

        if (! $config['enabled']) {
            return false;
        }

        $post = $target->relationLoaded('post')
            ? $target->post
            : $target->post()->withoutGlobalScopes()->first();
        $override = $post?->auto_repost;

        if ($override === false) {
            return false;
        }

        if (! $this->timingDue($target, $now, $config)) {
            return false;
        }

        // Explicit per-post opt-in bypasses the performance gate.
        if ($override === true) {
            return true;
        }

        return $this->passesGate($target, $now, $config);
    }

    /**
     * @param  array{min_delay_hours: int, max_delay_hours: int, plateau_streak: int}  $config
     */
    public function timingDue(PostTarget $target, CarbonImmutable $now, array $config): bool
    {
        if ($target->posted_at === null) {
            return false;
        }

        $posted = $target->posted_at;

        // Floor: never boost a barely-seen post.
        if ($now->lt($posted->addHours($config['min_delay_hours']))) {
            return false;
        }

        // Engagement plateaued -> re-surface now.
        if ((int) ($target->metrics_unchanged_streak ?? 0) >= $config['plateau_streak']) {
            return true;
        }

        // Ceiling: never wait forever (and the fallback when metrics are off).
        return $now->gte($posted->addHours($config['max_delay_hours']));
    }

    /**
     * @param  array{min_delay_hours: int, min_percentile: float}  $config
     */
    public function passesGate(PostTarget $target, CarbonImmutable $now, array $config): bool
    {
        $minSamples = (int) config('repost.baseline.min_samples');

        // The cohort is cached per account+platform, so exclude the current
        // target in memory rather than in SQL (keeping the query cache-shareable).
        $baseline = $this->baselineFor($target, $now, $config)
            ->reject(fn (PostTarget $other): bool => $other->id === $target->id);

        $e = $this->score->for($target);

        if ($baseline->count() < $minSamples) {
            return $e > 0; // cold start
        }

        $below = $baseline->filter(fn (PostTarget $other): bool => $this->score->for($other) < $e)->count();
        $percentile = $below / $baseline->count();

        return $percentile >= $config['min_percentile'];
    }

    /**
     * The account's published targets in the baseline window, memoized per
     * account+platform for the dispatch cycle. Includes the target under test;
     * callers exclude it. `$now` and the per-account `min_delay_hours` are stable
     * within a cycle, so a single query serves every target of that account.
     *
     * @param  array{min_delay_hours: int, min_percentile: float}  $config
     * @return Collection<int, PostTarget>
     */
    private function baselineFor(PostTarget $target, CarbonImmutable $now, array $config): Collection
    {
        $window = (int) config('repost.baseline.window_days');
        // Key on everything the window depends on. Within one dispatch cycle $now
        // and the account's min_delay_hours are constant, so every target of an
        // account still shares one query; a differing call simply misses.
        $key = implode('|', [
            $target->connected_account_id,
            $target->platform->value,
            $config['min_delay_hours'],
            $now->getTimestamp(),
        ]);

        return $this->baselineCache[$key] ??= PostTarget::query()
            ->withoutGlobalScopes()
            ->where('connected_account_id', $target->connected_account_id)
            ->where('platform', $target->platform->value)
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('posted_at')
            ->where('posted_at', '>=', $now->subDays($window))
            ->where('posted_at', '<=', $now->subHours($config['min_delay_hours']))
            ->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Repost;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;

class RepostEligibility
{
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

        $account = $target->account()->withoutGlobalScopes()->first();

        if ($account === null || $account->isDisabled()) {
            return false;
        }

        $config = $account->autoRepostConfig();

        if (! $config['enabled']) {
            return false;
        }

        $override = $target->post()->withoutGlobalScopes()->first()?->auto_repost;

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
        $window = (int) config('repost.baseline.window_days');
        $minSamples = (int) config('repost.baseline.min_samples');

        $baseline = PostTarget::query()
            ->withoutGlobalScopes()
            ->where('connected_account_id', $target->connected_account_id)
            ->where('platform', $target->platform->value)
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('posted_at')
            ->where('id', '!=', $target->id)
            ->where('posted_at', '>=', $now->subDays($window))
            ->where('posted_at', '<=', $now->subHours($config['min_delay_hours']))
            ->get();

        $e = $this->score->for($target);

        if ($baseline->count() < $minSamples) {
            return $e > 0; // cold start
        }

        $below = $baseline->filter(fn (PostTarget $other): bool => $this->score->for($other) < $e)->count();
        $percentile = $below / $baseline->count();

        return $percentile >= $config['min_percentile'];
    }
}

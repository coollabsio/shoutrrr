<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Platform;
use App\Models\Workspace;
use App\Services\Usage\UsageMeter;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use App\Support\UsagePricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

class WorkspaceSubscriptionGate
{
    /**
     * Operations that count as an X publish. XConnector meters URL-bearing
     * tweets under a pricier operation, so both must count toward the quota.
     *
     * @var list<string>
     */
    private const array X_PUBLISH_OPERATIONS = [UsageOperation::POST, UsageOperation::POST_WITH_URL];

    public function __construct(
        private readonly UsageMeter $usageMeter,
        private readonly UsagePricing $pricing,
        private readonly InstanceSettings $settings,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('subscriptions.enabled');
    }

    public function canPublish(Workspace $workspace): bool
    {
        return $this->canUseWorkspace($workspace);
    }

    public function canUseWorkspace(Workspace $workspace): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        return $workspace->is_initial || $workspace->subscribed('default');
    }

    public function canPublishX(Workspace $workspace): bool
    {
        if (! $this->isEnabled() || $this->isXUnlimited($workspace)) {
            return true;
        }

        return $this->canPublish($workspace)
            && $this->remainingXPosts($workspace) > 0
            && $this->remainingXBudgetMicrousd($workspace) >= $this->xPublishCostMicrousd();
    }

    /**
     * Monthly X publish quota for a workspace, or null when unlimited (unlimited
     * override / initial workspace, or a non-positive per-post cost).
     */
    public function monthlyXPostLimit(Workspace $workspace): ?int
    {
        if ($this->isXUnlimited($workspace)) {
            return null;
        }

        $publishCostMicrousd = $this->xPublishCostMicrousd();

        if ($publishCostMicrousd <= 0) {
            return null;
        }

        $budget = $this->monthlyXBudgetMicrousd($workspace);

        if ($budget === null) {
            return null;
        }

        return (int) floor($budget / $publishCostMicrousd);
    }

    public function remainingXPosts(Workspace $workspace): int
    {
        if (! $this->isEnabled() || $this->isXUnlimited($workspace)) {
            return PHP_INT_MAX;
        }

        $limit = $this->monthlyXPostLimit($workspace);

        if ($limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->currentXPostUsage($workspace));
    }

    /**
     * Monthly X budget in micro-USD for a workspace: a per-workspace override
     * (cents) when set, otherwise the global default. Null = unlimited.
     */
    public function monthlyXBudgetMicrousd(Workspace $workspace): ?int
    {
        if ($this->isXUnlimited($workspace)) {
            return null;
        }

        $override = $this->settings->xWorkspaceBudget($workspace->id);
        $cents = is_int($override) ? $override : (int) config('subscriptions.monthly_x_budget_cents');

        return $cents * 10_000;
    }

    public function remainingXBudgetMicrousd(Workspace $workspace): int
    {
        if (! $this->isEnabled() || $this->isXUnlimited($workspace)) {
            return PHP_INT_MAX;
        }

        $budget = $this->monthlyXBudgetMicrousd($workspace);

        if ($budget === null) {
            return PHP_INT_MAX;
        }

        return max(0, $budget - $this->currentXCostMicrousd($workspace));
    }

    /**
     * A workspace with no X ceiling: the initial workspace, or one an instance
     * owner has explicitly marked unlimited.
     */
    public function isXUnlimited(Workspace $workspace): bool
    {
        return $workspace->is_initial || $this->settings->xWorkspaceBudget($workspace->id) === 'unlimited';
    }

    /**
     * Total X API spend in the current billing period, recomputed from the current
     * pricing map (not stored cost columns) so pricing corrections apply to already
     * recorded usage. Sums every metered X operation — the whole budget is shared.
     */
    public function currentXCostMicrousd(Workspace $workspace): int
    {
        $periodStart = $this->billingPeriodStart($workspace);

        $quotaByOperation = $periodStart !== null
            ? $this->usageMeter->quotaByOperationSince($workspace->id, $periodStart, Platform::X)
            : $this->usageMeter->currentPeriodQuotaByOperation($workspace->id, Platform::X);

        $cost = 0;

        foreach ($quotaByOperation as $operation => $quota) {
            $cost += $this->pricing->costWeightMicrousd(Platform::X->value, (string) $operation, $quota);
        }

        return $cost;
    }

    /**
     * X publishes in the current billing period. For a subscribed workspace the
     * period is anchored to the subscription date (matching when Stripe renews),
     * so it counts successful publish events since the last cycle start. Without
     * a subscription it falls back to the calendar-month metering counters.
     */
    public function currentXPostUsage(Workspace $workspace): int
    {
        $periodStart = $this->billingPeriodStart($workspace);

        $count = 0;

        foreach (self::X_PUBLISH_OPERATIONS as $operation) {
            $count += $periodStart !== null
                ? $this->usageMeter->countSince($workspace->id, $periodStart, Platform::X, $operation)
                : $this->usageMeter->currentPeriodCount($workspace->id, Platform::X, $operation);
        }

        return $count;
    }

    private function xPublishCostMicrousd(): int
    {
        $costs = array_map(
            fn (string $operation): int => $this->pricing->costWeightMicrousd(Platform::X->value, $operation, 1),
            self::X_PUBLISH_OPERATIONS,
        );

        return max($costs);
    }

    /**
     * Start of the workspace's current billing cycle: the subscription creation
     * date advanced by whole months (no overflow, mirroring Stripe's anchor
     * behavior on short months). Null when the workspace has no subscription.
     */
    private function billingPeriodStart(Workspace $workspace): ?CarbonImmutable
    {
        $subscription = $workspace->subscription('default');

        if ($subscription === null || $subscription->created_at === null) {
            return null;
        }

        $anchor = CarbonImmutable::instance($subscription->created_at);
        $now = CarbonImmutable::instance(Date::now());

        $elapsedMonths = (int) $anchor->diffInMonths($now);
        $periodStart = $anchor->addMonthsNoOverflow($elapsedMonths);

        if ($periodStart->greaterThan($now)) {
            $periodStart = $anchor->addMonthsNoOverflow($elapsedMonths - 1);
        }

        return $periodStart;
    }
}

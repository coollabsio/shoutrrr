<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Platform;
use App\Models\Workspace;
use App\Services\Usage\UsageMeter;
use App\Support\UsageOperation;

class WorkspaceSubscriptionGate
{
    public function __construct(private readonly UsageMeter $usageMeter) {}

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

        return $this->isFirstWorkspace($workspace) || $workspace->subscribed('default');
    }

    public function canPublishX(Workspace $workspace): bool
    {
        if (! $this->isEnabled() || $this->isFirstWorkspace($workspace)) {
            return true;
        }

        return $this->canPublish($workspace) && $this->remainingXPosts($workspace) > 0;
    }

    public function monthlyXPostLimit(): int
    {
        $budgetCents = (int) config('subscriptions.monthly_x_budget_cents');
        $postCostCents = (float) config('subscriptions.x_post_cost_cents');

        if ($postCostCents <= 0.0) {
            return 0;
        }

        return (int) floor($budgetCents / $postCostCents);
    }

    public function remainingXPosts(Workspace $workspace): int
    {
        if (! $this->isEnabled() || $this->isFirstWorkspace($workspace)) {
            return PHP_INT_MAX;
        }

        return max(0, $this->monthlyXPostLimit() - $this->currentXPostUsage($workspace));
    }

    /**
     * Current-period X publishes, read from the usage-metering counters that the X
     * publish connector increments on every successful post. This replaces the old
     * standalone x_post_usages table.
     */
    public function currentXPostUsage(Workspace $workspace): int
    {
        return $this->usageMeter->currentPeriodCount($workspace->id, Platform::X, UsageOperation::POST);
    }

    private function isFirstWorkspace(Workspace $workspace): bool
    {
        $firstWorkspaceId = Workspace::query()
            ->oldest('created_at')
            ->oldest('id')
            ->value('id');

        return $firstWorkspaceId === $workspace->id;
    }
}

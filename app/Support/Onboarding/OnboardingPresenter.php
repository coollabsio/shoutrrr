<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\User;
use App\Models\Workspace;

class OnboardingPresenter
{
    /**
     * @return array{
     *     welcomed: bool,
     *     dismissed: bool,
     *     complete: bool,
     *     steps: list<array{key: string, label: string, done: bool, href: string, clickToComplete: bool}>
     * }
     */
    public static function make(Workspace $workspace, User $user): array
    {
        /** @var list<string> $progress */
        $progress = $workspace->onboarding_progress ?? [];

        $steps = [];

        foreach (OnboardingStep::cases() as $step) {
            if (! $user->hasAllPermissions([$step->permission()], $workspace->id)) {
                continue;
            }

            $steps[] = [
                'key' => $step->value,
                'label' => $step->label(),
                'done' => self::isStepDone($workspace, $step, $progress),
                'href' => route($step->routeName()),
                'clickToComplete' => $step->isClickToComplete(),
            ];
        }

        return [
            'welcomed' => $workspace->onboarding_welcomed_at !== null,
            'dismissed' => $workspace->onboarding_dismissed_at !== null,
            'complete' => $steps !== [] && collect($steps)->every(fn (array $step): bool => $step['done']),
            'steps' => $steps,
        ];
    }

    /**
     * @param  list<string>  $progress
     */
    private static function isStepDone(Workspace $workspace, OnboardingStep $step, array $progress): bool
    {
        return match ($step) {
            // Click-to-complete: done once the user has clicked through.
            OnboardingStep::Timezone => in_array($step->value, $progress, true),
            // Data-derived: done when the underlying thing actually exists.
            OnboardingStep::ConnectAccount => $workspace->connectedAccounts()->exists(),
            OnboardingStep::EnableSync => $workspace->syncPipelines()->exists(),
            OnboardingStep::FirstPost => $workspace->posts()->exists(),
            OnboardingStep::InviteTeammate => $workspace->members()->count() >= 2
                || $workspace->invitations()->exists(),
        };
    }
}

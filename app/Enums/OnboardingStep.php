<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The getting-started checklist steps. Most steps derive "done" from real data
 * (an account exists, a sync pipeline exists, a post exists, a teammate was invited). Timezone is the
 * exception: there is no reliable data signal (UTC is a legitimate choice, not
 * "unset"), so it is click-to-complete — clicking records its key in the
 * workspace's onboarding_progress. This enum is the single source of truth
 * shared by the presenter and the controller.
 */
enum OnboardingStep: string
{
    case ConnectAccount = 'connect_account';
    case EnableSync = 'enable_sync';
    case FirstPost = 'first_post';
    case Timezone = 'timezone';
    case InviteTeammate = 'invite_teammate';

    /**
     * Steps completed by clicking through (recorded in onboarding_progress)
     * rather than derived from real data.
     */
    public function isClickToComplete(): bool
    {
        return $this === self::Timezone;
    }

    public function label(): string
    {
        return match ($this) {
            self::ConnectAccount => 'Connect an account',
            self::EnableSync => 'Turn on sync',
            self::FirstPost => 'Compose your first post',
            self::Timezone => 'Set your workspace timezone',
            self::InviteTeammate => 'Invite a teammate',
        };
    }

    /**
     * Workspace permission required to see and complete the step.
     */
    public function permission(): string
    {
        return match ($this) {
            self::ConnectAccount => 'workspace.accounts.manage',
            self::EnableSync => 'workspace.settings.manage',
            self::FirstPost => 'workspace.update',
            self::Timezone => 'workspace.settings.manage',
            self::InviteTeammate => 'workspace.users.manage',
        };
    }

    /**
     * Named route the step links to (all param-less).
     */
    public function routeName(): string
    {
        return match ($this) {
            self::ConnectAccount => 'accounts.index',
            self::EnableSync => 'sync.index',
            self::FirstPost => 'dashboard',
            self::Timezone => 'settings.workspace',
            self::InviteTeammate => 'settings.workspace.members',
        };
    }
}

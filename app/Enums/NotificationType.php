<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case PostPublished = 'post_published';
    case PublishFailed = 'publish_failed';
    case WorkspaceInvite = 'workspace_invite';
    case AccountNeedsAttention = 'account_needs_attention';

    /**
     * Events whose in-app delivery is mandatory — the preferences matrix may not
     * silence them in the bell, only toggle their email.
     */
    public function inAppAlwaysOn(): bool
    {
        return match ($this) {
            self::PublishFailed, self::WorkspaceInvite, self::AccountNeedsAttention => true,
            default => false,
        };
    }

    public function emailOnByDefault(): bool
    {
        return match ($this) {
            self::PublishFailed, self::WorkspaceInvite, self::AccountNeedsAttention => true,
            default => false,
        };
    }
}

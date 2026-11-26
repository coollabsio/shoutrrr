<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can view a Direct Post. Which of these a creator may choose depends on
 * their account (public or private) and is read from creator info each time;
 * these are only the values TikTok's API understands.
 */
enum TikTokPrivacyLevel: string
{
    case PublicToEveryone = 'PUBLIC_TO_EVERYONE';
    case MutualFollowFriends = 'MUTUAL_FOLLOW_FRIENDS';
    case FollowerOfCreator = 'FOLLOWER_OF_CREATOR';
    case SelfOnly = 'SELF_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::PublicToEveryone => 'Everyone',
            self::MutualFollowFriends => 'Friends',
            self::FollowerOfCreator => 'Followers',
            self::SelfOnly => 'Only me',
        };
    }
}

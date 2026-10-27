import type { Link } from '@inertiajs/react';

import { edit as editAppearance } from '@/routes/appearance';
import { edit as editConnections } from '@/routes/connections';
import { preferences as notificationPreferences } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

export type AccountSettingsNavKey =
    | 'profile'
    | 'security'
    | 'connections'
    | 'appearance'
    | 'notifications';

export type AccountSettingsNavItem = {
    key: AccountSettingsNavKey;
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
};

export function accountSettingsNavItems({
    hasSocialProviders,
}: {
    hasSocialProviders: boolean;
}): AccountSettingsNavItem[] {
    return [
        { key: 'profile', title: 'Profile', href: editProfile() },
        { key: 'security', title: 'Security', href: editSecurity() },
        ...(hasSocialProviders
            ? [
                  {
                      key: 'connections' as const,
                      title: 'Connected accounts',
                      href: editConnections(),
                  },
              ]
            : []),
        { key: 'appearance', title: 'Appearance', href: editAppearance() },
        {
            key: 'notifications',
            title: 'Notifications',
            href: notificationPreferences(),
        },
    ];
}

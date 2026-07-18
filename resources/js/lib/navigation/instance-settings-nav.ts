import type { Link } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';

export type InstanceSettingsNavKey =
    | 'general'
    | 'polling'
    | 'platforms'
    | 'usage'
    | 'admins';

export type InstanceSettingsNavItem = {
    key: InstanceSettingsNavKey;
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
};

export function instanceSettingsNavItems(): InstanceSettingsNavItem[] {
    return [
        {
            key: 'general',
            title: 'General',
            href: InstanceSettingsController.edit(),
        },
        {
            key: 'polling',
            title: 'Polling',
            href: InstanceSettingsController.polling(),
        },
        {
            key: 'platforms',
            title: 'Platforms',
            href: InstanceSettingsController.platforms(),
        },
        {
            key: 'usage',
            title: 'Usage',
            href: InstanceSettingsController.usage(),
        },
        {
            key: 'admins',
            title: 'Admins',
            href: InstanceSettingsController.admins(),
        },
    ];
}

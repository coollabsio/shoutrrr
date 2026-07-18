import type { Link } from '@inertiajs/react';

import BillingController from '@/actions/App/Http/Controllers/BillingController';
import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import LegalPagesController from '@/actions/App/Http/Controllers/Settings/LegalPagesController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';

export type WorkspaceSettingsNavKey =
    | 'overview'
    | 'members'
    | 'apiKeys'
    | 'legal'
    | 'subscription';

export type WorkspaceSettingsNavItem = {
    key: WorkspaceSettingsNavKey;
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
};

export function workspaceSettingsNavItems({
    permissions,
    billingEnabled,
}: {
    permissions: string[];
    billingEnabled: boolean;
}): WorkspaceSettingsNavItem[] {
    const canManageSettings = permissions.includes('workspace.settings.manage');
    const canManageBilling = permissions.includes('workspace.billing.manage');

    const items: WorkspaceSettingsNavItem[] = [
        {
            key: 'overview',
            title: 'Overview',
            href: WorkspaceSettingsController.showOverview(),
        },
        {
            key: 'members',
            title: 'Members',
            href: WorkspaceSettingsController.showMembers(),
        },
    ];

    if (canManageSettings) {
        items.push({
            key: 'apiKeys',
            title: 'API keys',
            href: ApiKeysController.index(),
        });
        items.push({
            key: 'legal',
            title: 'Legal pages',
            href: LegalPagesController.edit(),
        });
    }

    if (billingEnabled && canManageBilling) {
        items.push({
            key: 'subscription',
            title: 'Subscription',
            href: BillingController.index(),
        });
    }

    return items;
}

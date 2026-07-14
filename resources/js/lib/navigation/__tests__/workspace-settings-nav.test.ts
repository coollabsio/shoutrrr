import { describe, expect, it } from 'vitest';

import { workspaceSettingsNavItems } from '../workspace-settings-nav';

describe('workspaceSettingsNavItems', () => {
    it('always includes overview and members', () => {
        const keys = workspaceSettingsNavItems({
            permissions: [],
            billingEnabled: false,
        }).map((item) => item.key);

        expect(keys).toEqual(['overview', 'members']);
    });

    it('adds API keys only with the settings-manage permission', () => {
        const keys = workspaceSettingsNavItems({
            permissions: ['workspace.settings.manage'],
            billingEnabled: false,
        }).map((item) => item.key);

        expect(keys).toContain('apiKeys');
    });

    it('adds subscription only when billing is enabled and manageable', () => {
        expect(
            workspaceSettingsNavItems({
                permissions: ['workspace.billing.manage'],
                billingEnabled: false,
            }).map((item) => item.key),
        ).not.toContain('subscription');

        expect(
            workspaceSettingsNavItems({
                permissions: ['workspace.billing.manage'],
                billingEnabled: true,
            }).map((item) => item.key),
        ).toContain('subscription');
    });
});

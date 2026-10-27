import { describe, expect, it } from 'vitest';

import { accountSettingsNavItems } from '../account-settings-nav';

describe('accountSettingsNavItems', () => {
    it('lists the account settings family', () => {
        const titles = accountSettingsNavItems({
            hasSocialProviders: false,
        }).map((item) => item.title);

        expect(titles).toEqual([
            'Profile',
            'Security',
            'Appearance',
            'Notifications',
        ]);
    });

    it('includes connected accounts when social login is enabled', () => {
        const keys = accountSettingsNavItems({
            hasSocialProviders: true,
        }).map((item) => item.key);

        expect(keys).toContain('connections');
    });
});

import { describe, expect, it } from 'vitest';

import { instanceSettingsNavItems } from '../instance-settings-nav';

describe('instanceSettingsNavItems', () => {
    it('includes every instance settings section', () => {
        const keys = instanceSettingsNavItems().map((item) => item.key);

        expect(keys).toEqual([
            'general',
            'polling',
            'platforms',
            'usage',
            'admins',
        ]);
    });

    it('labels sections for the sidebar sub navigation', () => {
        const titles = instanceSettingsNavItems().map((item) => item.title);

        expect(titles).toEqual([
            'General',
            'Polling',
            'Platforms',
            'Usage',
            'Admins',
        ]);
    });
});

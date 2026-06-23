import { describe, expect, it } from 'vitest';

import { appSidebarHeaderBackground } from '../app-sidebar-header-background';

describe('appSidebarHeaderBackground', () => {
    it('makes only the dashboard navbar 80% opaque', () => {
        expect(appSidebarHeaderBackground('dashboard')).toBe(
            'bg-background/80',
        );
    });

    it('keeps the regular navbar background on other pages', () => {
        expect(appSidebarHeaderBackground('posts/index')).toBe(
            'bg-background/85',
        );
    });
});

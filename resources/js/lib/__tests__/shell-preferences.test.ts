// @vitest-environment jsdom

import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    customThemePresets,
    parseCustomTheme,
    readShellPreferences,
    updateShellPreferences,
} from '@/lib/shell-preferences';

function memoryStorage() {
    const values = new Map<string, string>();

    return {
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => values.set(key, value),
        removeItem: (key: string) => values.delete(key),
        clear: () => values.clear(),
    };
}

describe('shell preferences', () => {
    beforeEach(() => {
        vi.stubGlobal('localStorage', memoryStorage());
    });

    it('keeps an existing Shoutrrr appearance when Coolify theme is unset', () => {
        localStorage.setItem('appearance', 'dark');

        expect(readShellPreferences().theme).toBe('dark');
        expect(readShellPreferences().navItems).toBe('3d');
    });

    it('lets Coolify theme win and treats the old purple value as custom', () => {
        localStorage.setItem('appearance', 'light');
        localStorage.setItem('theme', 'purple');

        expect(readShellPreferences().theme).toBe('custom');
    });

    it('parses a four-color share code', () => {
        expect(parseCustomTheme('#111111,#222222,#333333,#444444')).toEqual({
            sidebar: '#111111',
            selected: '#222222',
            accent: '#333333',
            background: '#444444',
        });
        expect(parseCustomTheme('nope')).toBeNull();
    });

    it('keeps a saved flat navigation style', () => {
        localStorage.setItem('navItems', 'flat');

        expect(readShellPreferences().navItems).toBe('flat');
    });

    it('writes theme and the legacy appearance key together', () => {
        updateShellPreferences({ theme: 'dark' });

        expect(localStorage.getItem('theme')).toBe('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
    });

    it('starts custom themes from the Shoutrrr green default', () => {
        expect(customThemePresets[0]).toEqual({
            name: 'Shoutrrr',
            theme: null,
            swatch: {
                sidebar: '#0d1307',
                selected: '#1c2414',
                accent: '#7ccf00',
                background: '#0a0a0a',
            },
        });
        expect(customThemePresets.map((preset) => preset.name)).not.toContain(
            'Coolify',
        );
    });
});

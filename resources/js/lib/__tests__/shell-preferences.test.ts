// @vitest-environment jsdom

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    applyShellPreferences,
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

const themeVariables = [
    '--theme-bg',
    '--theme-text',
    '--theme-sidebar',
    '--theme-sidebar-text',
    '--theme-selected',
    '--theme-selected-text',
    '--theme-accent',
    '--theme-accent-foreground',
];

/** Runs the pre-paint inline script from the Blade partial against the current localStorage. */
function runPrePaintScript(): void {
    const blade = readFileSync(
        resolve(
            import.meta.dirname,
            '../../../views/partials/theme-script.blade.php',
        ),
        'utf8',
    );
    const body = blade
        .match(/<script[^>]*>([\s\S]*)<\/script>/)![1]
        .replace(/\{\{[^}]*\}\}/g, 'system');

    // Evaluates the repo's own inline script; jsdom does not run injected <script> tags here.
    // oxlint-disable-next-line no-implied-eval
    new Function(body)();
}

function resetRoot(): void {
    const root = document.documentElement;
    root.removeAttribute('style');
    root.removeAttribute('class');
    delete root.dataset.theme;
}

function paintedTheme(): Record<string, string | boolean> {
    const root = document.documentElement;

    return {
        dark: root.classList.contains('dark'),
        ...Object.fromEntries(
            themeVariables.map((name) => [
                name,
                root.style.getPropertyValue(name),
            ]),
        ),
    };
}

describe('pre-paint theme script', () => {
    beforeEach(() => {
        vi.stubGlobal('localStorage', memoryStorage());
        resetRoot();
    });

    it.each(['#f2458f', '#bd93f9', '#2f81f7', '#808080', '#0a0a0a', '#ffffff'])(
        'paints custom color %s the same way hydration does',
        (color) => {
            localStorage.setItem('theme', 'custom');
            localStorage.setItem(
                'customTheme',
                [color, color, color, color].join(','),
            );

            runPrePaintScript();
            const prePaint = paintedTheme();
            resetRoot();
            applyShellPreferences(readShellPreferences());

            expect(prePaint).toEqual(paintedTheme());
        },
    );

    it('falls back to the same default swatch as hydration without a saved custom theme', () => {
        localStorage.setItem('theme', 'custom');

        runPrePaintScript();
        const prePaint = paintedTheme();
        resetRoot();
        applyShellPreferences(readShellPreferences());

        expect(prePaint).toEqual(paintedTheme());
        expect(prePaint['--theme-accent']).toBe(
            customThemePresets[0].swatch.accent,
        );
    });
});

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

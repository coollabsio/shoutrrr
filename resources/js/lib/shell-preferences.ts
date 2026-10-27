import { useSyncExternalStore } from 'react';

/**
 * Browser shell preferences shared with Coolify: `theme`, `pageWidth`,
 * `navStyle`, `navItems`, `chartTimes`, and `customTheme`.
 *
 * Shoutrrr already stored light/dark/system as `appearance`. When `theme` is
 * missing, that value is the theme. Writing a theme also updates `appearance`
 * (custom resolves to light or dark) so the existing cookie and first paint
 * stay valid.
 */

export type ThemeChoice = 'light' | 'dark' | 'system' | 'custom';
export type PageWidth = 'full' | 'centered';
export type NavStyle = 'default' | 'floating';
export type NavItems = 'flat' | '3d';
export type ChartTimes = 'local' | 'utc';

export type CustomThemeColors = {
    sidebar: string;
    selected: string;
    accent: string;
    background: string;
};

export const customThemeKeys = [
    'sidebar',
    'selected',
    'accent',
    'background',
] as const;

const preset = (
    name: string,
    theme: CustomThemeColors | null,
    swatch?: CustomThemeColors,
): {
    name: string;
    theme: CustomThemeColors | null;
    swatch: CustomThemeColors;
} => ({
    name,
    theme,
    swatch: swatch ??
        theme ?? {
            sidebar: '#1a0f2d',
            selected: '#1c1c1c',
            accent: '#6b16ed',
            background: '#0a0a0a',
        },
});

export const customThemePresets = [
    preset('Coolify', null, {
        sidebar: '#1a0f2d',
        selected: '#1c1c1c',
        accent: '#6b16ed',
        background: '#0a0a0a',
    }),
    preset('Midnight', {
        sidebar: '#0f172a',
        selected: '#2e4a7d',
        accent: '#f2458f',
        background: '#1a2338',
    }),
    preset('Forest', {
        sidebar: '#12342b',
        selected: '#2c6e57',
        accent: '#f4b860',
        background: '#1b2a25',
    }),
    preset('Ember', {
        sidebar: '#3b1410',
        selected: '#8f2d1f',
        accent: '#ffb86b',
        background: '#241816',
    }),
    preset('Slate', {
        sidebar: '#2b3038',
        selected: '#4a5361',
        accent: '#6cc4f5',
        background: '#353b45',
    }),
    preset('Nord', {
        sidebar: '#2e3440',
        selected: '#4c566a',
        accent: '#88c0d0',
        background: '#3b4252',
    }),
    preset('Dracula', {
        sidebar: '#21222c',
        selected: '#44475a',
        accent: '#bd93f9',
        background: '#282a36',
    }),
    preset('GitHub Dark', {
        sidebar: '#010409',
        selected: '#21262d',
        accent: '#2f81f7',
        background: '#0d1117',
    }),
    preset('Aubergine', {
        sidebar: '#3f0e40',
        selected: '#1164a3',
        accent: '#1164a3',
        background: '#ffffff',
    }),
    preset('GitHub Light', {
        sidebar: '#f6f8fa',
        selected: '#dde4ec',
        accent: '#0969da',
        background: '#ffffff',
    }),
    preset('Paper', {
        sidebar: '#ebe6da',
        selected: '#d9d1bf',
        accent: '#c2410c',
        background: '#f7f4ec',
    }),
    preset('Blossom', {
        sidebar: '#fde8f1',
        selected: '#f9c4dc',
        accent: '#d61f69',
        background: '#fff7fa',
    }),
];

const listeners = new Set<() => void>();

export type ShellPreferences = {
    theme: ThemeChoice;
    pageWidth: PageWidth;
    navStyle: NavStyle;
    navItems: NavItems;
    chartTimes: ChartTimes;
    customTheme: CustomThemeColors;
};

let current: ShellPreferences = {
    theme: 'system',
    pageWidth: 'full',
    navStyle: 'default',
    navItems: '3d',
    chartTimes: 'local',
    customTheme: customThemePresets[0].swatch,
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

export function normalizeHex(color: string | null | undefined): string | null {
    const hex = String(color ?? '')
        .trim()
        .replace(/^#/, '')
        .toLowerCase();

    if (/^[a-f\d]{6}$/.test(hex)) {
        return `#${hex}`;
    }

    if (/^[a-f\d]{3}$/.test(hex)) {
        return `#${hex.replace(/./g, '$&$&')}`;
    }

    return null;
}

export function parseCustomTheme(
    code: string | null | undefined,
): CustomThemeColors | null {
    const colors = String(code ?? '')
        .split(/[\s,]+/)
        .filter(Boolean)
        .map((part) => normalizeHex(part));

    if (colors.length !== 4 || colors.includes(null)) {
        return null;
    }

    return {
        sidebar: colors[0] as string,
        selected: colors[1] as string,
        accent: colors[2] as string,
        background: colors[3] as string,
    };
}

export function serializeCustomTheme(theme: CustomThemeColors): string {
    return customThemeKeys.map((key) => theme[key]).join(',');
}

function luminance(hex: string): number {
    const normalized = normalizeHex(hex) ?? '#000000';
    const channels = normalized
        .match(/[a-f\d]{2}/gi)!
        .map((channel) => parseInt(channel, 16) / 255)
        .map((channel) =>
            channel <= 0.04045
                ? channel / 12.92
                : ((channel + 0.055) / 1.055) ** 2.4,
        );

    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

export function isDarkColor(hex: string): boolean {
    const value = luminance(normalizeHex(hex) ?? '#000000');

    return (
        1.05 / (value + 0.05) >= (value + 0.05) / (luminance('#18181b') + 0.05)
    );
}

export function readableTextOn(hex: string): string {
    return isDarkColor(hex) ? '#f4f4f5' : '#18181b';
}

function storage(): Storage | null {
    if (typeof globalThis.localStorage === 'undefined') {
        return null;
    }

    return globalThis.localStorage;
}

function readTheme(): ThemeChoice {
    const store = storage();
    if (!store) {
        return 'system';
    }

    const stored = store.getItem('theme');
    if (stored === 'purple') {
        return 'custom';
    }
    if (
        stored === 'light' ||
        stored === 'dark' ||
        stored === 'system' ||
        stored === 'custom'
    ) {
        return stored;
    }

    const appearance = store.getItem('appearance');
    if (
        appearance === 'light' ||
        appearance === 'dark' ||
        appearance === 'system'
    ) {
        return appearance;
    }

    return 'system';
}

function readCustomTheme(): CustomThemeColors {
    if (typeof window === 'undefined') {
        return customThemePresets[0].swatch;
    }

    return (
        parseCustomTheme(storage()?.getItem('customTheme')) ??
        customThemePresets[0].swatch
    );
}

export function readShellPreferences(): ShellPreferences {
    if (typeof window === 'undefined') {
        return current;
    }

    const store = storage();
    const pageWidth = store?.getItem('pageWidth');
    const navStyle = store?.getItem('navStyle');
    const navItems = store?.getItem('navItems');
    const chartTimes = store?.getItem('chartTimes');

    return {
        theme: readTheme(),
        pageWidth:
            pageWidth === 'centered' || pageWidth === 'full'
                ? pageWidth
                : 'full',
        navStyle: navStyle === 'floating' ? 'floating' : 'default',
        navItems: navItems === 'flat' ? 'flat' : '3d',
        chartTimes: chartTimes === 'utc' ? 'utc' : 'local',
        customTheme: readCustomTheme(),
    };
}

function resolvedIsDark(preferences: ShellPreferences): boolean {
    if (preferences.theme === 'custom') {
        return isDarkColor(preferences.customTheme.background);
    }

    return (
        preferences.theme === 'dark' ||
        (preferences.theme === 'system' && prefersDark())
    );
}

export function resolvedThemeChoice(
    preferences: ShellPreferences,
): 'light' | 'dark' {
    return resolvedIsDark(preferences) ? 'dark' : 'light';
}

export function applyShellPreferences(preferences: ShellPreferences): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const dark = resolvedIsDark(preferences);

    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    root.dataset.theme =
        preferences.theme === 'custom' ? 'custom' : dark ? 'dark' : 'light';
    root.dataset.pageWidth = preferences.pageWidth;
    root.dataset.navStyle = preferences.navStyle;
    root.dataset.navItems = preferences.navItems;

    if (preferences.theme === 'custom') {
        const colors = preferences.customTheme;
        const sidebarDark = isDarkColor(colors.sidebar);
        root.style.setProperty('--theme-bg', colors.background);
        root.style.setProperty(
            '--theme-text',
            readableTextOn(colors.background),
        );
        root.style.setProperty('--theme-sidebar', colors.sidebar);
        root.style.setProperty(
            '--theme-sidebar-text',
            readableTextOn(colors.sidebar),
        );
        root.style.setProperty('--theme-selected', colors.selected);
        root.style.setProperty(
            '--theme-selected-text',
            readableTextOn(colors.selected),
        );
        root.style.setProperty('--theme-accent', colors.accent);
        root.style.setProperty(
            '--theme-accent-foreground',
            readableTextOn(colors.accent),
        );
        root.dataset.sidebarScheme =
            sidebarDark === dark ? '' : sidebarDark ? 'dark' : 'light';
        if (root.dataset.sidebarScheme === '') {
            delete root.dataset.sidebarScheme;
        }
    } else {
        delete root.dataset.sidebarScheme;
        root.style.removeProperty('--theme-bg');
        root.style.removeProperty('--theme-text');
        root.style.removeProperty('--theme-sidebar');
        root.style.removeProperty('--theme-sidebar-text');
        root.style.removeProperty('--theme-selected');
        root.style.removeProperty('--theme-selected-text');
        root.style.removeProperty('--theme-accent');
        root.style.removeProperty('--theme-accent-foreground');
    }
}

function persist(next: ShellPreferences): void {
    current = next;
    if (typeof window !== 'undefined') {
        const appearance =
            next.theme === 'custom' ? resolvedThemeChoice(next) : next.theme;
        const store = storage();
        store?.setItem('theme', next.theme);
        store?.setItem('appearance', appearance);
        store?.setItem('pageWidth', next.pageWidth);
        store?.setItem('navStyle', next.navStyle);
        store?.setItem('navItems', next.navItems);
        store?.setItem('chartTimes', next.chartTimes);
        store?.setItem('customTheme', serializeCustomTheme(next.customTheme));
        setCookie('appearance', appearance);
    }
    applyShellPreferences(next);
    listeners.forEach((listener) => listener());
}

export function initializeShellPreferences(): void {
    if (typeof window === 'undefined') {
        return;
    }

    current = readShellPreferences();
    applyShellPreferences(current);
    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => {
            if (current.theme === 'system') {
                applyShellPreferences(current);
                listeners.forEach((listener) => listener());
            }
        });
}

export function subscribeShellPreferences(callback: () => void): () => void {
    listeners.add(callback);

    return () => listeners.delete(callback);
}

export function getShellPreferences(): ShellPreferences {
    return current;
}

export function updateShellPreferences(
    patch: Partial<Omit<ShellPreferences, 'customTheme'>> & {
        customTheme?: CustomThemeColors;
    },
): void {
    persist({ ...current, ...patch });
}

export function useShellPreferences(): ShellPreferences & {
    resolved: 'light' | 'dark';
} {
    const preferences = useSyncExternalStore(
        subscribeShellPreferences,
        getShellPreferences,
        getShellPreferences,
    );

    return {
        ...preferences,
        resolved: resolvedThemeChoice(preferences),
    };
}

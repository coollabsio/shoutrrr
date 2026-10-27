import { useSyncExternalStore } from 'react';

import {
    getShellPreferences,
    initializeShellPreferences,
    resolvedThemeChoice,
    subscribeShellPreferences,
    updateShellPreferences,
    type ThemeChoice,
} from '@/lib/shell-preferences';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ThemeChoice;

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

export function initializeTheme(): void {
    initializeShellPreferences();
}

export function useAppearance(): UseAppearanceReturn {
    const preferences = useSyncExternalStore(
        subscribeShellPreferences,
        getShellPreferences,
        getShellPreferences,
    );

    return {
        appearance: preferences.theme,
        resolvedAppearance: resolvedThemeChoice(preferences),
        updateAppearance: (mode) => updateShellPreferences({ theme: mode }),
    };
}

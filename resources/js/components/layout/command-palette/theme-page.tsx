import { CommandGroup, CommandItem } from '@/components/ui/command';
import { Moon, Settings, Sun } from '@/components/ui/icons';
import type { Appearance } from '@/hooks/use-appearance';

interface ThemePageProps {
    run: (fn: () => void) => () => void;
    updateAppearance: (appearance: Appearance) => void;
}

export function ThemePage({ run, updateAppearance }: ThemePageProps) {
    return (
        <CommandGroup heading="Switch theme">
            <CommandItem
                value="theme light"
                onSelect={run(() => updateAppearance('light'))}
            >
                <Sun className="size-4" aria-hidden />
                Light
            </CommandItem>
            <CommandItem
                value="theme dark"
                onSelect={run(() => updateAppearance('dark'))}
            >
                <Moon className="size-4" aria-hidden />
                Dark
            </CommandItem>
            <CommandItem
                value="theme system"
                onSelect={run(() => updateAppearance('system'))}
            >
                <Settings className="size-4" aria-hidden />
                System
            </CommandItem>
        </CommandGroup>
    );
}

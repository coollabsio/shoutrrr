import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { ChevronLeft } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import {
    customThemePresets,
    getShellPreferences,
    openCustomThemeDialog,
    parseCustomTheme,
    serializeCustomTheme,
    updateShellPreferences,
    useShellPreferences,
    type CustomThemeColors,
    type ThemeChoice,
} from '@/lib/shell-preferences';

function Option({
    selected,
    label,
    onSelect,
}: {
    selected: boolean;
    label: string;
    onSelect: () => void;
}) {
    return (
        <DropdownMenuItem closeOnClick={false} onClick={onSelect}>
            <span className="flex-1">{label}</span>
            {selected ? <span aria-hidden="true">✓</span> : null}
        </DropdownMenuItem>
    );
}

export function AppearancePanel({ onBack }: { onBack: () => void }) {
    const preferences = useShellPreferences();

    return (
        <div>
            <DropdownMenuItem closeOnClick={false} onClick={onBack}>
                <ChevronLeft />
                Appearance
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Theme</DropdownMenuLabel>
            {(
                [
                    ['light', 'Light'],
                    ['system', 'System'],
                    ['dark', 'Dark'],
                ] as const
            ).map(([value, label]) => (
                <Option
                    key={value}
                    label={label}
                    selected={preferences.theme === value}
                    onSelect={() => updateShellPreferences({ theme: value })}
                />
            ))}
            <Option
                label="Custom"
                selected={preferences.theme === 'custom'}
                onSelect={() => {
                    updateShellPreferences({ theme: 'custom' });
                    openCustomThemeDialog();
                }}
            />
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Page width</DropdownMenuLabel>
            <Option
                label="Full width"
                selected={preferences.pageWidth === 'full'}
                onSelect={() => updateShellPreferences({ pageWidth: 'full' })}
            />
            <Option
                label="Centered"
                selected={preferences.pageWidth === 'centered'}
                onSelect={() =>
                    updateShellPreferences({ pageWidth: 'centered' })
                }
            />
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Navigation style</DropdownMenuLabel>
            <Option
                label="Default"
                selected={preferences.navStyle === 'default'}
                onSelect={() => updateShellPreferences({ navStyle: 'default' })}
            />
            <Option
                label="Floating"
                selected={preferences.navStyle === 'floating'}
                onSelect={() =>
                    updateShellPreferences({ navStyle: 'floating' })
                }
            />
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Style</DropdownMenuLabel>
            <Option
                label="3D"
                selected={preferences.navItems === '3d'}
                onSelect={() => updateShellPreferences({ navItems: '3d' })}
            />
            <Option
                label="Flat"
                selected={preferences.navItems === 'flat'}
                onSelect={() => updateShellPreferences({ navItems: 'flat' })}
            />
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Chart times</DropdownMenuLabel>
            <Option
                label="Your time"
                selected={preferences.chartTimes === 'local'}
                onSelect={() => updateShellPreferences({ chartTimes: 'local' })}
            />
            <Option
                label="Your time and UTC"
                selected={preferences.chartTimes === 'utc'}
                onSelect={() => updateShellPreferences({ chartTimes: 'utc' })}
            />
        </div>
    );
}

const colorFields: { key: keyof CustomThemeColors; label: string }[] = [
    { key: 'sidebar', label: 'Sidebar' },
    { key: 'selected', label: 'Selected' },
    { key: 'accent', label: 'Accent' },
    { key: 'background', label: 'Background' },
];

export function CustomThemeDialog() {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<CustomThemeColors>(
        customThemePresets[0].swatch,
    );
    const [shareCode, setShareCode] = useState('');

    useEffect(() => {
        const openDialog = () => {
            const saved = getShellPreferences().customTheme;
            setDraft(saved);
            setShareCode(serializeCustomTheme(saved));
            setOpen(true);
        };
        window.addEventListener('shoutrrr:custom-theme', openDialog);

        return () =>
            window.removeEventListener('shoutrrr:custom-theme', openDialog);
    }, []);

    function choose(next: CustomThemeColors) {
        setDraft(next);
        setShareCode(serializeCustomTheme(next));
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Custom theme</DialogTitle>
                    <DialogDescription>
                        Colors in this browser.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-wrap gap-2">
                    {customThemePresets.map((preset) => (
                        <button
                            key={preset.name}
                            type="button"
                            className="rounded-md border px-2 py-1 text-xs"
                            onClick={() =>
                                choose(preset.theme ?? preset.swatch)
                            }
                        >
                            {preset.name}
                        </button>
                    ))}
                </div>
                <div className="flex flex-col gap-2">
                    {colorFields.map((field) => (
                        <label
                            key={field.key}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="color"
                                aria-label={field.label}
                                value={draft[field.key]}
                                onChange={(event) =>
                                    choose({
                                        ...draft,
                                        [field.key]: event.target.value,
                                    })
                                }
                                className="size-8 shrink-0 cursor-pointer rounded-md border bg-transparent"
                            />
                            <span className="w-24">{field.label}</span>
                            <Input
                                value={draft[field.key]}
                                aria-label={`${field.label} hex`}
                                onChange={(event) => {
                                    const next = parseCustomTheme(
                                        serializeCustomTheme({
                                            ...draft,
                                            [field.key]: event.target.value,
                                        }),
                                    );
                                    if (next) {
                                        choose(next);
                                    }
                                }}
                                className="font-mono text-xs"
                            />
                        </label>
                    ))}
                </div>
                <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                    Share code
                    <Input
                        value={shareCode}
                        aria-label="Share code"
                        onChange={(event) => {
                            setShareCode(event.target.value);
                            const parsed = parseCustomTheme(event.target.value);
                            if (parsed) {
                                setDraft(parsed);
                            }
                        }}
                        className="font-mono text-xs"
                    />
                </label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            updateShellPreferences({
                                theme: 'custom' satisfies ThemeChoice,
                                customTheme: draft,
                            });
                            setOpen(false);
                        }}
                    >
                        Save theme
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

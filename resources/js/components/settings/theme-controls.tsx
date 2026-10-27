import { useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Check, CheckCircle2, Copy } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import {
    customThemePresets,
    parseCustomTheme,
    readableTextOn,
    serializeCustomTheme,
    updateShellPreferences,
    useShellPreferences,
    type CustomThemeColors,
} from '@/lib/shell-preferences';
import { cn } from '@/lib/utils';

function ChoiceCard({
    selected,
    label,
    description,
    preview,
    onClick,
}: {
    selected: boolean;
    label: string;
    description: string;
    preview: ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'overflow-hidden rounded-[10px] border bg-card text-left transition-shadow hover:shadow-sm',
                selected
                    ? 'border-primary ring-2 ring-primary/25'
                    : 'border-border',
            )}
        >
            <div className="flex h-20 items-center justify-center border-b bg-muted/40">
                {preview}
            </div>
            <div className="p-3">
                <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-semibold">{label}</span>
                    {selected ? (
                        <CheckCircle2 className="size-4 text-primary" />
                    ) : null}
                </div>
                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    {description}
                </p>
            </div>
        </button>
    );
}

function Section({
    title,
    description,
    children,
    columns = 'sm:grid-cols-2',
}: {
    title: string;
    description: string;
    children: ReactNode;
    columns?: string;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            <div className={cn('grid gap-3', columns)}>{children}</div>
        </section>
    );
}

function Swatch({ colors }: { colors: CustomThemeColors }) {
    return (
        <span className="flex h-12 w-24 overflow-hidden rounded-md border">
            {(['sidebar', 'selected', 'accent', 'background'] as const).map(
                (key) => (
                    <span
                        key={key}
                        className="h-full flex-1"
                        style={{ background: colors[key] }}
                    />
                ),
            )}
        </span>
    );
}

const colorFields: {
    key: keyof CustomThemeColors;
    label: string;
    description: string;
}[] = [
    {
        key: 'sidebar',
        label: 'Sidebar',
        description: 'The navigation on the left',
    },
    {
        key: 'selected',
        label: 'Selected',
        description: 'The current page in the sidebar',
    },
    {
        key: 'accent',
        label: 'Accent',
        description: 'Buttons, links, and highlights',
    },
    {
        key: 'background',
        label: 'Background',
        description: 'Pages, cards, and panels',
    },
];

function sameTheme(
    left: CustomThemeColors | null,
    right: CustomThemeColors | null,
): boolean {
    if (left === null || right === null) {
        return left === right;
    }

    return serializeCustomTheme(left) === serializeCustomTheme(right);
}

function CustomThemeDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const preferences = useShellPreferences();
    const initial =
        preferences.theme === 'custom' ? preferences.customTheme : null;
    const [draft, setDraft] = useState<CustomThemeColors | null>(initial);
    const [shareCode, setShareCode] = useState(
        serializeCustomTheme(initial ?? customThemePresets[0].swatch),
    );
    const [invalidCode, setInvalidCode] = useState(false);
    const colors = draft ?? customThemePresets[0].swatch;

    function choose(next: CustomThemeColors | null) {
        setDraft(next);
        setShareCode(
            serializeCustomTheme(next ?? customThemePresets[0].swatch),
        );
        setInvalidCode(false);
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    const starting =
                        preferences.theme === 'custom'
                            ? preferences.customTheme
                            : null;
                    setDraft(starting);
                    setShareCode(
                        serializeCustomTheme(
                            starting ?? customThemePresets[0].swatch,
                        ),
                    );
                    setInvalidCode(false);
                }
                onOpenChange(next);
            }}
        >
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Custom theme</DialogTitle>
                    <DialogDescription>
                        Colors in this browser. Text stays readable on every
                        color.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-6 lg:grid-cols-2">
                    <div>
                        <div className="mb-2 text-xs font-medium text-muted-foreground">
                            Presets
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {customThemePresets.map((preset) => {
                                const selected = sameTheme(preset.theme, draft);

                                return (
                                    <button
                                        key={preset.name}
                                        type="button"
                                        aria-pressed={selected}
                                        className="flex flex-col gap-1.5 rounded-lg p-1 text-left"
                                        onClick={() => choose(preset.theme)}
                                    >
                                        <Swatch colors={preset.swatch} />
                                        <span className="truncate text-xs text-muted-foreground">
                                            {preset.name}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div className="flex flex-col gap-5">
                        <div
                            className="flex h-48 overflow-hidden rounded-lg border text-xs"
                            aria-hidden="true"
                        >
                            <div
                                className="flex w-2/5 flex-col gap-0.5 p-2.5"
                                style={{
                                    background: colors.sidebar,
                                    color: readableTextOn(colors.sidebar),
                                }}
                            >
                                <div className="mb-2 px-2 text-sm font-semibold">
                                    Shoutrrr
                                </div>
                                <div className="rounded-md px-2 py-1.5 opacity-75">
                                    Dashboard
                                </div>
                                <div
                                    className="rounded-md px-2 py-1.5 font-medium"
                                    style={{
                                        background: colors.selected,
                                        color: readableTextOn(colors.selected),
                                    }}
                                >
                                    Projects
                                </div>
                            </div>
                            <div
                                className="flex min-w-0 flex-1 flex-col gap-2 p-3"
                                style={{
                                    background: colors.background,
                                    color: readableTextOn(colors.background),
                                }}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-semibold">
                                        Projects
                                    </span>
                                    <span
                                        className="rounded-md px-2 py-1 text-[11px] font-medium"
                                        style={{
                                            background: colors.accent,
                                            color: readableTextOn(
                                                colors.accent,
                                            ),
                                        }}
                                    >
                                        + New
                                    </span>
                                </div>
                                <div className="rounded-md border p-2.5 opacity-90">
                                    My first project
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-col gap-2.5">
                            <div className="text-xs font-medium text-muted-foreground">
                                Colors
                            </div>
                            {colorFields.map((field) => (
                                <div
                                    key={field.key}
                                    className="flex items-center gap-3"
                                >
                                    <label className="relative size-9 shrink-0 cursor-pointer overflow-hidden rounded-md border">
                                        <span
                                            className="absolute inset-0"
                                            style={{
                                                background: colors[field.key],
                                            }}
                                        />
                                        <input
                                            type="color"
                                            aria-label={`${field.label} color`}
                                            value={colors[field.key]}
                                            onChange={(event) =>
                                                choose({
                                                    ...colors,
                                                    [field.key]:
                                                        event.target.value,
                                                })
                                            }
                                            className="absolute inset-0 cursor-pointer opacity-0"
                                        />
                                    </label>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-medium">
                                            {field.label}
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {field.description}
                                        </div>
                                    </div>
                                    <Input
                                        value={colors[field.key]}
                                        aria-label={`${field.label} hex color`}
                                        onChange={(event) => {
                                            const next = parseCustomTheme(
                                                serializeCustomTheme({
                                                    ...colors,
                                                    [field.key]:
                                                        event.target.value,
                                                }),
                                            );
                                            if (next) {
                                                choose(next);
                                            }
                                        }}
                                        className="w-28 font-mono text-sm"
                                    />
                                </div>
                            ))}
                        </div>
                        <div>
                            <label
                                htmlFor="custom-theme-share-code"
                                className="mb-2 block text-xs font-medium text-muted-foreground"
                            >
                                Share code
                            </label>
                            <div className="flex gap-2">
                                <Input
                                    id="custom-theme-share-code"
                                    value={shareCode}
                                    aria-invalid={invalidCode}
                                    onChange={(event) => {
                                        const value = event.target.value;
                                        setShareCode(value);
                                        const parsed = parseCustomTheme(value);
                                        if (parsed) {
                                            setDraft(parsed);
                                            setInvalidCode(false);
                                        } else {
                                            setInvalidCode(value.trim() !== '');
                                        }
                                    }}
                                    className="font-mono text-sm"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Copy share code"
                                    onClick={() => {
                                        void navigator.clipboard
                                            ?.writeText(shareCode)
                                            .then(() => toast.success('Copied'))
                                            .catch(() =>
                                                toast.error(
                                                    'Copy the code manually.',
                                                ),
                                            );
                                    }}
                                >
                                    <Copy />
                                </Button>
                            </div>
                            <p
                                className={cn(
                                    'mt-1.5 text-xs',
                                    invalidCode
                                        ? 'text-destructive'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {invalidCode
                                    ? 'Enter four hex colors, separated by commas.'
                                    : 'Paste a code that someone shared, or copy yours.'}
                            </p>
                        </div>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            if (draft === null) {
                                updateShellPreferences({ theme: 'dark' });
                            } else {
                                updateShellPreferences({
                                    theme: 'custom',
                                    customTheme: draft,
                                });
                            }
                            onOpenChange(false);
                        }}
                    >
                        <Check />
                        Save theme
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ThemeControls() {
    const preferences = useShellPreferences();
    const [dialogOpen, setDialogOpen] = useState(false);

    return (
        <div className="flex w-full flex-col gap-8">
            <Section
                title="Color theme"
                description="Choose the color scheme used in this browser."
                columns="sm:grid-cols-2 lg:grid-cols-4"
            >
                <ChoiceCard
                    label="Light"
                    description="Bright surfaces and dark text."
                    selected={preferences.theme === 'light'}
                    onClick={() => updateShellPreferences({ theme: 'light' })}
                    preview={
                        <span className="h-8 w-20 rounded-md border bg-white shadow-sm" />
                    }
                />
                <ChoiceCard
                    label="System"
                    description="Follow your operating system."
                    selected={preferences.theme === 'system'}
                    onClick={() => updateShellPreferences({ theme: 'system' })}
                    preview={
                        <span className="h-8 w-20 rounded-md border bg-gradient-to-r from-white via-neutral-400 to-neutral-950 shadow-sm" />
                    }
                />
                <ChoiceCard
                    label="Dark"
                    description="Dark surfaces and soft contrast."
                    selected={preferences.theme === 'dark'}
                    onClick={() => updateShellPreferences({ theme: 'dark' })}
                    preview={
                        <span className="h-8 w-20 rounded-md border bg-neutral-900 shadow-sm" />
                    }
                />
                <ChoiceCard
                    label="Custom"
                    description="Pick a preset or your own colors."
                    selected={preferences.theme === 'custom'}
                    onClick={() => setDialogOpen(true)}
                    preview={<Swatch colors={preferences.customTheme} />}
                />
            </Section>

            <Section
                title="Page width"
                description="Choose how content uses the available browser width."
            >
                <ChoiceCard
                    label="Full width"
                    description="Use all available space for page content."
                    selected={preferences.pageWidth === 'full'}
                    onClick={() =>
                        updateShellPreferences({ pageWidth: 'full' })
                    }
                    preview={
                        <span className="h-8 w-28 rounded-md border bg-background" />
                    }
                />
                <ChoiceCard
                    label="Centered"
                    description="Keep content centered at a comfortable maximum width."
                    selected={preferences.pageWidth === 'centered'}
                    onClick={() =>
                        updateShellPreferences({ pageWidth: 'centered' })
                    }
                    preview={
                        <span className="mx-auto h-8 w-16 rounded-md border bg-background" />
                    }
                />
            </Section>

            <Section
                title="Navigation style"
                description="Choose how the top bar and sidebars sit on the page."
            >
                <ChoiceCard
                    label="Default"
                    description="Top bar and sidebars run edge to edge."
                    selected={preferences.navStyle === 'default'}
                    onClick={() =>
                        updateShellPreferences({ navStyle: 'default' })
                    }
                    preview={
                        <span className="text-xs text-muted-foreground">
                            Edge to edge
                        </span>
                    }
                />
                <ChoiceCard
                    label="Floating"
                    description="Framed panels float on the page."
                    selected={preferences.navStyle === 'floating'}
                    onClick={() =>
                        updateShellPreferences({ navStyle: 'floating' })
                    }
                    preview={
                        <span className="text-xs text-muted-foreground">
                            Inset frame
                        </span>
                    }
                />
            </Section>

            <Section
                title="Style"
                description="Choose how buttons and sidebar items look."
            >
                <ChoiceCard
                    label="3D"
                    description="Sidebar icons sit on a raised edge."
                    selected={preferences.navItems === '3d'}
                    onClick={() => updateShellPreferences({ navItems: '3d' })}
                    preview={
                        <span className="size-6 rounded-md border bg-background shadow-[0_2px_0_rgb(0_0_0/0.22)]" />
                    }
                />
                <ChoiceCard
                    label="Flat"
                    description="Plain rows with a highlight for the current page."
                    selected={preferences.navItems === 'flat'}
                    onClick={() => updateShellPreferences({ navItems: 'flat' })}
                    preview={
                        <span className="h-6 w-24 rounded-md bg-black/5 dark:bg-white/8" />
                    }
                />
            </Section>

            <Section
                title="Chart times"
                description="Choose which times chart tooltips show."
            >
                <ChoiceCard
                    label="Your time"
                    description="Show the time in the time zone of this browser."
                    selected={preferences.chartTimes === 'local'}
                    onClick={() =>
                        updateShellPreferences({ chartTimes: 'local' })
                    }
                    preview={<span className="text-xs">Oct 24, 12:30</span>}
                />
                <ChoiceCard
                    label="Your time and UTC"
                    description="Also show the UTC time."
                    selected={preferences.chartTimes === 'utc'}
                    onClick={() =>
                        updateShellPreferences({ chartTimes: 'utc' })
                    }
                    preview={
                        <span className="text-xs">12:30 and 10:30 UTC</span>
                    }
                />
            </Section>

            <CustomThemeDialog open={dialogOpen} onOpenChange={setDialogOpen} />
        </div>
    );
}

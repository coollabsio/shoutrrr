import {
    ArrowUp,
    Briefcase,
    Check,
    Languages,
    type LucideIcon,
    Maximize2,
    Minimize2,
    RotateCcw,
    Smile,
    Sparkles,
    SpellCheck,
    Square,
    Wand2,
    X,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import type { AiSuggestion } from '@/lib/compose/composer-state';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

type RunKind = 'rewrite' | 'preset' | 'generate' | 'adapt';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    suggestion: AiSuggestion;
    platform?: PlatformName;
    currentText: string;
    onRun: (kind: RunKind, payload: { action?: string; instruction?: string }) => void;
    onRedo: () => void;
    onAccept: (text: string) => void;
    /** Cancel any stream and clear the result, returning to the action list. */
    onReset: () => void;
};

const TONE: { action: string; label: string; icon: LucideIcon }[] = [
    { action: 'professional', label: 'Professional', icon: Briefcase },
    { action: 'casual', label: 'Casual', icon: Smile },
    { action: 'punchy', label: 'Punchy', icon: Zap },
];

const LENGTH: { action: string; label: string; icon: LucideIcon }[] = [
    { action: 'shorten', label: 'Shorten', icon: Minimize2 },
    { action: 'expand', label: 'Expand', icon: Maximize2 },
];

const PLATFORM_LABELS: Partial<Record<PlatformName, string>> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
};

const TRIGGER_CLASS = cn(
    'group inline-flex h-8 items-center gap-1.5 rounded-md border border-primary/25 bg-primary/[0.06] px-2.5 text-[12px] font-medium text-primary transition-colors sm:h-7',
    'hover:border-primary/40 hover:bg-primary/10',
    'data-[state=open]:border-primary/50 data-[state=open]:bg-primary/12',
);

export function ShoutAiPopover({
    open,
    onOpenChange,
    suggestion,
    platform,
    currentText,
    onRun,
    onRedo,
    onAccept,
    onReset,
}: Props) {
    const isMobile = useIsMobile();

    // Once a result is streaming or ready, an accidental click/tap outside must
    // NOT throw the work away — only the explicit close, Accept, or Discard does.
    const hasResult = suggestion.status !== 'idle';
    const keepOpenOnOutside = (event: Event) => {
        if (hasResult) {
            event.preventDefault();
        }
    };

    const trigger = (
        <button type="button" title="ShoutAI" className={TRIGGER_CLASS}>
            <Sparkles
                className="size-3.5 transition-transform group-hover:scale-110 motion-reduce:transition-none"
                aria-hidden="true"
            />
            <span>ShoutAI</span>
        </button>
    );

    const surface = (showClose: boolean) => (
        <Surface
            suggestion={suggestion}
            platform={platform}
            currentText={currentText}
            onRun={onRun}
            onRedo={onRedo}
            onAccept={onAccept}
            onReset={onReset}
            onClose={() => onOpenChange(false)}
            showClose={showClose}
        />
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetTrigger asChild>{trigger}</SheetTrigger>
                <SheetContent
                    side="bottom"
                    onInteractOutside={keepOpenOnOutside}
                    className="max-h-[85vh] gap-0 rounded-t-2xl p-0"
                >
                    <SheetTitle className="sr-only">ShoutAI</SheetTitle>
                    {surface(false)}
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={6}
                onInteractOutside={keepOpenOnOutside}
                className="max-h-[min(75vh,560px)] w-[min(360px,calc(100vw-1.5rem))] overflow-hidden rounded-xl p-0"
            >
                {surface(true)}
            </PopoverContent>
        </Popover>
    );
}

function Surface({
    suggestion,
    platform,
    currentText,
    onRun,
    onRedo,
    onAccept,
    onReset,
    onClose,
    showClose,
}: {
    suggestion: AiSuggestion;
    platform?: PlatformName;
    currentText: string;
    onRun: Props['onRun'];
    onRedo: () => void;
    onAccept: (text: string) => void;
    onReset: () => void;
    onClose: () => void;
    showClose: boolean;
}) {
    const [instruction, setInstruction] = useState('');

    const streaming = suggestion.status === 'streaming';
    const ready = suggestion.status === 'ready';
    const isError = suggestion.status === 'error';
    const showResult = suggestion.status !== 'idle';
    const hasText = currentText.trim().length > 0;

    function generate() {
        if (instruction.trim() === '') {
            return;
        }
        onRun('generate', { instruction });
    }

    return (
        <div className="flex max-h-[inherit] flex-col">
            {/* Header */}
            <div className="flex shrink-0 items-center justify-between border-b border-border px-3 py-2.5">
                <span className="flex items-center gap-2 text-[12px] font-semibold tracking-tight text-foreground">
                    <span className="grid size-5 place-items-center rounded-md bg-primary/10 text-primary ring-1 ring-primary/15">
                        <Sparkles className="size-3" aria-hidden="true" />
                    </span>
                    ShoutAI
                    {showResult ? (
                        <span className="font-normal text-muted-foreground">
                            ·{' '}
                            {streaming ? 'Writing…' : ready ? 'Review' : 'Error'}
                        </span>
                    ) : null}
                </span>
                {showClose ? (
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close ShoutAI"
                        className="rounded-md p-1 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <X className="size-3.5" />
                    </button>
                ) : null}
            </div>

            {showResult ? (
                <Result
                    suggestion={suggestion}
                    streaming={streaming}
                    ready={ready}
                    isError={isError}
                    onAccept={onAccept}
                    onRedo={onRedo}
                    onReset={onReset}
                />
            ) : (
                <div className="min-h-0 flex-1 overflow-y-auto p-2.5">
                    {/* Generate — the input-first hero */}
                    <div className="flex items-center gap-1.5 rounded-lg border border-border bg-background px-2 py-1.5 transition-[border-color,box-shadow] focus-within:border-primary/40 focus-within:ring-2 focus-within:ring-primary/10">
                        <Sparkles
                            className="size-3.5 shrink-0 text-primary/70"
                            aria-hidden="true"
                        />
                        <input
                            aria-label="Describe a post for ShoutAI to write"
                            value={instruction}
                            onChange={(e) => setInstruction(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    generate();
                                }
                            }}
                            placeholder="Describe a post to write…"
                            className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
                        />
                        <button
                            type="button"
                            onClick={generate}
                            disabled={instruction.trim() === ''}
                            aria-label="Generate post"
                            className="grid size-6 shrink-0 place-items-center rounded-md bg-primary text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-30"
                        >
                            <ArrowUp className="size-3.5" />
                        </button>
                    </div>

                    {/* One-tap actions over the current draft */}
                    <GroupLabel>Improve</GroupLabel>
                    <ActionRow
                        icon={Wand2}
                        label="Rewrite"
                        accent
                        disabled={!hasText}
                        onClick={() => onRun('rewrite', {})}
                    />
                    <ActionRow
                        icon={SpellCheck}
                        label="Fix grammar"
                        disabled={!hasText}
                        onClick={() => onRun('preset', { action: 'fix_grammar' })}
                    />

                    <GroupLabel>Tone</GroupLabel>
                    {TONE.map(({ action, label, icon }) => (
                        <ActionRow
                            key={action}
                            icon={icon}
                            label={label}
                            disabled={!hasText}
                            onClick={() => onRun('preset', { action })}
                        />
                    ))}

                    <GroupLabel>Length</GroupLabel>
                    {LENGTH.map(({ action, label, icon }) => (
                        <ActionRow
                            key={action}
                            icon={icon}
                            label={label}
                            disabled={!hasText}
                            onClick={() => onRun('preset', { action })}
                        />
                    ))}

                    {platform ? (
                        <>
                            <GroupLabel>Platform</GroupLabel>
                            <ActionRow
                                icon={Languages}
                                label={`Adapt for ${PLATFORM_LABELS[platform] ?? platform}`}
                                accent
                                disabled={!hasText}
                                onClick={() => onRun('adapt', {})}
                            />
                        </>
                    ) : null}

                    {!hasText ? (
                        <p className="px-2 pt-2 text-[11px] text-muted-foreground">
                            Write a draft to rewrite, adjust, or adapt it.
                        </p>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function GroupLabel({ children }: { children: React.ReactNode }) {
    return (
        <div className="px-2 pt-3 pb-1 text-[10px] font-medium tracking-[0.08em] text-muted-foreground uppercase">
            {children}
        </div>
    );
}

function ActionRow({
    icon: Icon,
    label,
    onClick,
    disabled,
    accent = false,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
    disabled?: boolean;
    accent?: boolean;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className="group flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-[13px] text-foreground transition-colors hover:bg-primary/[0.06] hover:text-primary disabled:pointer-events-none disabled:opacity-40"
        >
            <Icon
                className={cn(
                    'size-4 shrink-0 transition-colors group-hover:text-primary',
                    accent ? 'text-primary' : 'text-muted-foreground',
                )}
                aria-hidden="true"
            />
            <span>{label}</span>
        </button>
    );
}

function Result({
    suggestion,
    streaming,
    ready,
    isError,
    onAccept,
    onRedo,
    onReset,
}: {
    suggestion: AiSuggestion;
    streaming: boolean;
    ready: boolean;
    isError: boolean;
    onAccept: (text: string) => void;
    onRedo: () => void;
    onReset: () => void;
}) {
    return (
        <div className="min-h-0 flex-1 space-y-2.5 overflow-y-auto p-2.5">
            <div
                className={cn(
                    'rounded-lg border p-3 text-[13px] leading-relaxed whitespace-pre-wrap',
                    isError
                        ? 'border-destructive/30 bg-destructive/[0.03] text-destructive'
                        : 'border-border bg-background text-foreground',
                )}
            >
                {isError ? (
                    (suggestion.error ?? 'Something went wrong. Try again.')
                ) : (
                    <span
                        className={cn(
                            streaming &&
                                'after:ml-0.5 after:animate-pulse after:text-primary/60 after:content-["▍"]',
                        )}
                    >
                        {suggestion.output}
                    </span>
                )}
            </div>

            {streaming ? (
                <div className="flex">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={onReset}
                        className="ml-auto h-7 text-muted-foreground hover:text-foreground"
                    >
                        <Square className="size-3" />
                        Stop
                    </Button>
                </div>
            ) : null}

            {ready ? (
                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        className="h-7"
                        onClick={() => onAccept(suggestion.output)}
                    >
                        <Check className="size-3.5" />
                        Accept
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-7"
                        onClick={onRedo}
                    >
                        <RotateCcw className="size-3.5" />
                        Redo
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={onReset}
                        className="ml-auto h-7 text-muted-foreground hover:text-foreground"
                    >
                        Discard
                    </Button>
                </div>
            ) : null}

            {isError ? (
                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-7"
                        onClick={onRedo}
                    >
                        <RotateCcw className="size-3.5" />
                        Try again
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={onReset}
                        className="ml-auto h-7 text-muted-foreground hover:text-foreground"
                    >
                        Back
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

import {
    Briefcase,
    Check,
    type LucideIcon,
    Maximize2,
    Minimize2,
    RotateCcw,
    Smile,
    Sparkles,
    SpellCheck,
    Wand2,
    X,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AiSuggestion } from '@/lib/compose/composer-state';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

type Props = {
    open: boolean;
    onClose: () => void;
    suggestion: AiSuggestion;
    platform?: PlatformName;
    limit: number;
    currentText: string;
    onRun: (
        kind: 'rewrite' | 'preset' | 'generate' | 'adapt',
        payload: { action?: string; instruction?: string },
    ) => void;
    onRedo: () => void;
    onAccept: (text: string) => void;
    onCancel: () => void;
};

const QUICK_ACTIONS: { action: string; label: string; icon: LucideIcon }[] = [
    { action: 'shorten', label: 'Shorten', icon: Minimize2 },
    { action: 'expand', label: 'Expand', icon: Maximize2 },
    { action: 'professional', label: 'Professional', icon: Briefcase },
    { action: 'casual', label: 'Casual', icon: Smile },
    { action: 'punchy', label: 'Punchy', icon: Zap },
    { action: 'fix_grammar', label: 'Fix grammar', icon: SpellCheck },
];

const PLATFORM_LABELS: Partial<Record<PlatformName, string>> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
};

export function AssistantPanel({
    open,
    onClose,
    suggestion,
    platform,
    currentText,
    onRun,
    onRedo,
    onAccept,
    onCancel,
}: Props) {
    const [instruction, setInstruction] = useState('');

    if (!open) {
        return null;
    }

    const streaming = suggestion.status === 'streaming';
    const ready = suggestion.status === 'ready';
    const isError = suggestion.status === 'error';
    const hasOutput = streaming || ready || isError;
    const hasText = currentText.trim().length > 0;

    return (
        <div className="border-t border-border bg-muted/40 px-3 py-3 sm:px-[14px]">
            {/* Header row */}
            <div className="mb-2.5 flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-[12px] font-semibold tracking-tight text-foreground">
                    <span className="flex size-5 items-center justify-center rounded-md bg-primary/10 text-primary ring-1 ring-primary/15">
                        <Sparkles className="size-3" aria-hidden="true" />
                    </span>
                    ShoutAI
                </span>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close ShoutAI"
                    className="rounded-md p-1 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <X className="size-3.5" />
                </button>
            </div>

            {/* Quick actions over the current draft */}
            <div className="space-y-1.5">
                {/* Primary improve action */}
                <button
                    type="button"
                    disabled={streaming || !hasText}
                    onClick={() => onRun('rewrite', {})}
                    className="flex w-full items-center gap-2.5 rounded-lg border border-primary/25 bg-primary/[0.06] px-3 py-2 text-left transition-colors hover:border-primary/40 hover:bg-primary/10 disabled:pointer-events-none disabled:opacity-40"
                >
                    <Wand2
                        className="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span className="flex flex-col leading-tight">
                        <span className="text-[12px] font-medium text-foreground">
                            Rewrite
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            Improve clarity &amp; impact
                        </span>
                    </span>
                </button>

                {/* Transform grid — distinct, scannable actions */}
                <div className="grid grid-cols-3 gap-1.5">
                    {QUICK_ACTIONS.map(({ action, label, icon: Icon }) => (
                        <button
                            key={action}
                            type="button"
                            disabled={streaming || !hasText}
                            onClick={() => onRun('preset', { action })}
                            className="flex flex-col items-center justify-center gap-1 rounded-lg border border-border bg-background px-1.5 py-2 text-[11px] text-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-primary disabled:pointer-events-none disabled:opacity-40"
                        >
                            <Icon className="size-4" aria-hidden="true" />
                            <span className="leading-none">{label}</span>
                        </button>
                    ))}
                </div>

                {/* Adapt — only shown when a platform tab is active */}
                {platform && (
                    <button
                        type="button"
                        disabled={streaming || !hasText}
                        onClick={() => onRun('adapt', {})}
                        className="flex w-full items-center justify-center gap-1.5 rounded-lg border border-primary/25 bg-primary/[0.04] px-3 py-1.5 text-[11px] font-medium text-primary transition-colors hover:border-primary/40 hover:bg-primary/10 disabled:pointer-events-none disabled:opacity-40"
                    >
                        <Sparkles className="size-3.5" aria-hidden="true" />
                        Adapt for {PLATFORM_LABELS[platform] ?? platform}
                    </button>
                )}
            </div>

            {/* Generate from prompt */}
            <div className="mt-2 flex gap-1.5">
                <Input
                    value={instruction}
                    onChange={(e) => setInstruction(e.target.value)}
                    placeholder="Describe a post to generate…"
                    disabled={streaming}
                    className="h-7 text-[13px]"
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && instruction.trim()) {
                            onRun('generate', { instruction });
                        }
                    }}
                />
                <Button
                    type="button"
                    size="sm"
                    disabled={streaming || instruction.trim() === ''}
                    onClick={() => onRun('generate', { instruction })}
                >
                    Generate
                </Button>
            </div>

            {/* Output area — streaming / ready / error */}
            {hasOutput && (
                <div
                    className={cn(
                        'mt-3 animate-in fade-in-0 slide-in-from-top-1 rounded-lg border bg-background p-3 shadow-[0_1px_2px_0_rgb(0_0_0/0.04)] transition-colors duration-200',
                        streaming &&
                            'border-l-2 border-l-primary border-border bg-primary/[0.02]',
                        ready && 'border-border',
                        isError && 'border-destructive/30 bg-destructive/[0.03]',
                    )}
                >
                    {isError ? (
                        <p className="text-[13px] text-destructive">
                            {suggestion.error ?? 'Something went wrong.'}
                        </p>
                    ) : (
                        <p
                            className={cn(
                                'whitespace-pre-wrap text-[13px] leading-relaxed text-foreground',
                                streaming &&
                                    'after:ml-0.5 after:animate-pulse after:content-["▍"] after:text-primary/60',
                            )}
                        >
                            {suggestion.output}
                        </p>
                    )}

                    {/* Accept / Redo / Discard — only when ready */}
                    {ready && (
                        <div className="mt-3 flex items-center gap-1.5 border-t border-border/60 pt-2.5">
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => onAccept(suggestion.output)}
                            >
                                <Check className="size-3.5" />
                                Accept
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={onRedo}
                            >
                                <RotateCcw className="size-3.5" />
                                Redo
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={onCancel}
                                className="ml-auto text-muted-foreground hover:text-foreground"
                            >
                                Discard
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

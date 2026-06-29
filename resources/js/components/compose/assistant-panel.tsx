import { Check, RotateCcw, Sparkles, X } from 'lucide-react';
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
    onAccept: (text: string) => void;
    onCancel: () => void;
};

const PRESETS: { action: string; label: string }[] = [
    { action: '', label: 'Rewrite' },
    { action: 'shorten', label: 'Shorten' },
    { action: 'expand', label: 'Expand' },
    { action: 'professional', label: 'Professional' },
    { action: 'casual', label: 'Casual' },
    { action: 'punchy', label: 'Punchy' },
    { action: 'fix_grammar', label: 'Fix grammar' },
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
                <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground">
                    <Sparkles className="size-3.5 text-primary/70" aria-hidden="true" />
                    AI assistant
                </span>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close assistant"
                    className="rounded-md p-1 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <X className="size-3.5" />
                </button>
            </div>

            {/* Preset chips */}
            <div className="flex flex-wrap gap-1.5">
                {PRESETS.map((preset) => (
                    <button
                        key={preset.label}
                        type="button"
                        disabled={streaming || !hasText}
                        onClick={() =>
                            onRun(preset.action === '' ? 'rewrite' : 'preset', {
                                action: preset.action || undefined,
                            })
                        }
                        className="rounded-full border border-border bg-background px-2.5 py-1 text-[12px] text-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 disabled:pointer-events-none disabled:opacity-40"
                    >
                        {preset.label}
                    </button>
                ))}

                {/* Adapt chip — only shown when a platform tab is active */}
                {platform && (
                    <button
                        type="button"
                        disabled={streaming || !hasText}
                        onClick={() => onRun('adapt', {})}
                        className="rounded-full border border-primary/30 bg-primary/5 px-2.5 py-1 text-[12px] text-primary/80 transition-colors hover:border-primary/50 hover:bg-primary/10 disabled:pointer-events-none disabled:opacity-40"
                    >
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
                        'mt-3 rounded-lg border bg-background p-3 transition-colors',
                        streaming && 'border-l-2 border-l-primary/40 border-border',
                        ready && 'border-border',
                        isError && 'border-destructive/30',
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
                        <div className="mt-2.5 flex items-center gap-1.5">
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
                                variant="ghost"
                                onClick={() =>
                                    onRun(
                                        suggestion.action ? 'preset' : 'rewrite',
                                        { action: suggestion.action ?? undefined },
                                    )
                                }
                            >
                                <RotateCcw className="size-3.5" />
                                Redo
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={onCancel}
                                className="text-muted-foreground hover:text-foreground"
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

import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

export type FormatOption<T extends string> = {
    value: T;
    label: string;
    icon: LucideIcon;
};

type Props<T extends string> = {
    value: T;
    onChange: (value: T) => void;
    options: FormatOption<T>[];
    ariaLabel: string;
};

/**
 * Segmented Post/Story-style switch that flips the preview between a platform's
 * surfaces. It only changes what the preview renders — it does not affect what
 * gets published — so users can visualize both a feed post and a story from the
 * same draft.
 */
export function PreviewFormatToggle<T extends string>({
    value,
    onChange,
    options,
    ariaLabel,
}: Props<T>) {
    return (
        <div
            role="radiogroup"
            aria-label={ariaLabel}
            className="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/40 p-0.5"
        >
            {options.map((option) => {
                const active = option.value === value;
                const Icon = option.icon;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-[12.5px] font-medium tracking-[-0.005em] transition-colors',
                            active
                                ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-3.5 shrink-0" aria-hidden />
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

import { ArrowDown, ArrowUp } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/** Signed, thousands-grouped delta: `+342`, `−1,204`, `0`. */
export function formatDelta(delta: number): string {
    const sign = delta > 0 ? '+' : delta < 0 ? '−' : '';

    return `${sign}${Math.abs(delta).toLocaleString()}`;
}

type DeltaChipProps = {
    /** Change over the period. `null` renders nothing — there was no baseline. */
    delta: number | null;
    /** Screen-reader context, e.g. "followers vs previous period". */
    label?: string;
    className?: string;
};

/**
 * A directional change indicator built on the shared Badge. Up reads as good
 * (green), down as a decline (red), and a flat period is quietly neutral.
 */
export function DeltaChip({ delta, label, className }: DeltaChipProps) {
    if (delta === null) {
        return null;
    }

    const direction = delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat';

    return (
        <Badge
            variant={
                direction === 'up'
                    ? 'success'
                    : direction === 'down'
                      ? 'destructive'
                      : 'secondary'
            }
            className={cn('gap-0.5 px-1.5 tabular-nums', className)}
        >
            {direction === 'up' && <ArrowUp aria-hidden="true" />}
            {direction === 'down' && <ArrowDown aria-hidden="true" />}
            {formatDelta(delta)}
            {label && <span className="sr-only"> {label}</span>}
        </Badge>
    );
}

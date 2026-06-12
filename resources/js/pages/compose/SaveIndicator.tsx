import { cn } from '@/lib/utils';

import type { SaveState } from './composer-state';

type SaveIndicatorProps = {
    state: SaveState;
    /** Epoch millis of the last successful save, or null when never saved. */
    lastSavedAt: number | null;
};

/**
 * Compact dot + label save status. The conflict resolution UI lives in
 * ConflictDialog (wired separately); this component only renders the status
 * pill (saved / saving / dirty / offline / conflict) and a relative
 * "Saved 12s ago" label.
 */
export default function SaveIndicator({
    state,
    lastSavedAt,
}: SaveIndicatorProps) {
    const label = formatSaveLabel(state, lastSavedAt);

    return (
        <div
            className={cn(
                'hidden shrink-0 items-center gap-1.5 pr-3 text-[11.5px] sm:flex',
                state === 'dirty' && 'text-amber-700 dark:text-amber-500',
                state === 'conflict' && 'text-destructive',
                state === 'offline' && 'text-amber-700 dark:text-amber-500',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    state === 'saved' && 'bg-primary',
                    state === 'saving' && 'animate-pulse bg-blue-500',
                    state === 'dirty' && 'bg-amber-500',
                    state === 'conflict' && 'bg-destructive',
                    state === 'offline' && 'bg-amber-500',
                )}
            />
            <span className="tabular-nums">{label}</span>
        </div>
    );
}

export function formatSaveLabel(
    state: SaveState,
    lastSavedAt: number | null,
): string {
    if (state === 'saving') {
        return 'Saving…';
    }
    if (state === 'dirty') {
        return 'Unsaved';
    }
    if (state === 'conflict') {
        return 'Conflict';
    }
    if (state === 'offline') {
        return 'Offline — saved locally';
    }
    if (!lastSavedAt) {
        return 'Saved';
    }
    const savedAgo = Math.max(0, Math.floor((Date.now() - lastSavedAt) / 1000));
    if (savedAgo < 5) {
        return 'Saved';
    }
    if (savedAgo < 60) {
        return `Saved ${savedAgo}s ago`;
    }
    const minutes = Math.floor(savedAgo / 60);

    return `Saved ${minutes}m ago`;
}

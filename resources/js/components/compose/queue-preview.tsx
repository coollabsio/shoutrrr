import { Link } from '@inertiajs/react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import {
    formatSlotLabel,
    type QueueSlotState,
} from '@/hooks/compose/use-next-slot';

type Props = {
    state: QueueSlotState;
    selectedSlot: string | null;
    onSelectSlot: (slot: string) => void;
};

/** The slot-preview line shown under the tabs when the Queue tab is selected. */
export function QueuePreview({ state, selectedSlot, onSelectSlot }: Props) {
    if (state.status === 'idle' || state.status === 'loading') {
        return (
            <span className="text-[11px] text-muted-foreground">
                Finding next slot…
            </span>
        );
    }

    if (state.status === 'no-schedule') {
        return (
            <span className="text-[11px] text-muted-foreground">
                No posting schedule yet —{' '}
                <Link
                    href={PostingScheduleController.show().url}
                    className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                >
                    Add slots
                </Link>
            </span>
        );
    }

    if (state.status === 'full') {
        return (
            <span className="text-[11px] text-muted-foreground">
                Queue is full for the next 14 days — pick a time instead.
            </span>
        );
    }

    if (state.status === 'error') {
        return (
            <span className="text-[11px] text-muted-foreground">
                Couldn’t load the next slot — try reopening the tab.
            </span>
        );
    }

    if (state.status === 'found') {
        if (state.slots.length > 1) {
            return (
                <label className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                    Add to
                    <select
                        value={selectedSlot ?? state.slot ?? state.slots[0]}
                        onChange={(event) => onSelectSlot(event.target.value)}
                        className="h-7 rounded-md border border-border bg-background px-2 text-[11px] font-medium text-foreground"
                    >
                        {state.slots.map((slot) => (
                            <option key={slot} value={slot}>
                                {formatSlotLabel(slot, state.tz)}
                            </option>
                        ))}
                    </select>
                </label>
            );
        }

        return (
            <span className="text-[11px] text-muted-foreground">
                Next open slot:{' '}
                <span className="font-medium text-foreground">
                    {state.slot ? formatSlotLabel(state.slot, state.tz) : ''}
                </span>
            </span>
        );
    }

    // Exhaustiveness guard: if a new QueueSlotStatus is added without a branch
    // here, this assignment fails to compile rather than silently rendering nothing.
    state.status satisfies never;

    return null;
}

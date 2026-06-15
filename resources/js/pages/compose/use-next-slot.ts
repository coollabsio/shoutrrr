import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { dayjs } from '@/lib/datetime/dayjs';
import { nextSlot } from '@/routes/posts';

/** Raw payload returned by GET posts/next-slot. */
export type NextSlotPayload = {
    has_schedule: boolean;
    slot: string | null;
    timezone: string;
};

export type QueueSlotStatus =
    | 'idle'
    | 'loading'
    | 'found'
    | 'no-schedule'
    | 'full'
    | 'error';

export type QueueSlotState = {
    status: QueueSlotStatus;
    /** ISO-8601 UTC instant when status === 'found', else null. */
    slot: string | null;
    /** The scheduling timezone the slot should be displayed in. */
    tz: string;
};

/** Map a resolved payload to one of the three terminal UI states. */
export function deriveQueueStatus(payload: NextSlotPayload): QueueSlotStatus {
    if (!payload.has_schedule) {
        return 'no-schedule';
    }
    if (payload.slot === null) {
        return 'full';
    }

    return 'found';
}

/** Format an ISO instant as a slot label in `tz`, e.g. "Tue, Jun 16 · 9:00 AM". */
export function formatSlotLabel(iso: string, tz: string): string {
    return dayjs(iso).tz(tz).format('ddd, MMM D · h:mm A');
}

/**
 * Fetch the next open posting slot while `active` is true (the Queue tab is
 * selected). Re-fetches whenever it transitions to active so the preview reflects
 * slots taken since the composer loaded. Returns 'idle' while inactive.
 */
export function useNextSlot(
    active: boolean,
    fallbackTz: string,
): QueueSlotState {
    const http = useHttp<Record<string, never>, NextSlotPayload>({});
    const [state, setState] = useState<QueueSlotState>({
        status: 'idle',
        slot: null,
        tz: fallbackTz,
    });

    useEffect(() => {
        if (!active) {
            setState({ status: 'idle', slot: null, tz: fallbackTz });

            return;
        }

        let cancelled = false;
        setState({ status: 'loading', slot: null, tz: fallbackTz });

        const failTerminal = () => {
            if (cancelled) {
                return;
            }
            setState({ status: 'error', slot: null, tz: fallbackTz });
        };

        void http.get(nextSlot().url, {
            onSuccess: (data: NextSlotPayload) => {
                if (cancelled) {
                    return;
                }
                setState({
                    status: deriveQueueStatus(data),
                    slot: data.slot,
                    tz: data.timezone || fallbackTz,
                });
            },
            // Any HTTP error (e.g. 500, or 404 when there is no current
            // workspace) must leave 'loading' for a terminal state.
            onHttpException: failTerminal,
            onNetworkError: failTerminal,
        });

        return () => {
            cancelled = true;
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- `http` is a stable ref from useHttp; including it re-fires the fetch every render
    }, [active, fallbackTz]);

    return state;
}

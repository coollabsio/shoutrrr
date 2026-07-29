import { router, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * A focus refresh this soon after another request is redundant, so alt-tabbing
 * repeatedly doesn't fire a request per switch.
 */
const FOCUS_REFRESH_THROTTLE_MS = 5_000;

type LiveProps = {
    /** Prop keys to refresh. Dot paths (`shell.unreadReplies`) are supported. */
    only: string[];
    /**
     * Merge props (e.g. `Inertia::scroll()`) that must be replaced rather than
     * appended — without this a partial reload duplicates the existing rows.
     */
    reset?: string[];
};

/** Extra props the mounted pages want refreshed alongside the app chrome. */
const registrations = new Set<LiveProps>();

let lastRequestAt = 0;

/**
 * Fold every registration into one partial reload, so a page that wants its own
 * data live costs no extra round trip on top of the chrome refresh.
 */
function refreshOptions(base: LiveProps) {
    const only = new Set(base.only);
    const reset = new Set(base.reset ?? []);

    registrations.forEach((registration) => {
        registration.only.forEach((key) => only.add(key));
        registration.reset?.forEach((key) => reset.add(key));
    });

    return {
        only: [...only],
        reset: [...reset],
        // A background refresh nobody asked for shouldn't flash the progress bar.
        showProgress: false,
        onStart: () => {
            lastRequestAt = Date.now();
        },
    };
}

/**
 * Add props to the app's refresh cycle for as long as this page is mounted.
 * Requires {@link useLivePropsPoll} in the surrounding chrome, which owns the
 * single request these keys ride along on.
 */
export function useLiveProps({ only, reset }: LiveProps): void {
    useEffect(() => {
        const registration = { only, reset };
        registrations.add(registration);

        return () => {
            registrations.delete(registration);
        };
    }, [only, reset]);
}

/**
 * Drives the app's one background refresh: polls on an interval (Inertia
 * throttles the poll to a tenth of the rate while the tab is hidden) and
 * refreshes immediately when the user returns to the tab, so a tab left open in
 * the background is current the moment it is looked at again.
 */
export function useLivePropsPoll({
    only,
    intervalMs,
}: {
    only: string[];
    intervalMs: number;
}): void {
    // The options callback runs on every tick, so props registered by whichever
    // page is mounted are picked up without restarting the poll.
    usePoll(
        intervalMs,
        () => refreshOptions({ only }),
        // `rest` waits for the previous request to finish before timing the next
        // tick, so a slow response never stacks up overlapping reloads.
        { mode: 'rest' },
    );

    useEffect(() => {
        function refreshOnReturn() {
            if (document.visibilityState !== 'visible') {
                return;
            }

            if (Date.now() - lastRequestAt < FOCUS_REFRESH_THROTTLE_MS) {
                return;
            }

            router.reload(refreshOptions({ only }));
        }

        window.addEventListener('focus', refreshOnReturn);
        document.addEventListener('visibilitychange', refreshOnReturn);

        return () => {
            window.removeEventListener('focus', refreshOnReturn);
            document.removeEventListener('visibilitychange', refreshOnReturn);
        };
    }, [only]);
}

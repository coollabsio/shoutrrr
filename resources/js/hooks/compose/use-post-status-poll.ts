import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

import { shouldPollPostStatus } from '@/lib/compose/publish-status';
import type { PostView } from '@/types/compose';

const POLL_INTERVAL_MS = 3000;

/** Keep async target statuses fresh across composer/read-only view changes. */
export function usePostStatusPoll(post: PostView | null) {
    const active = post ? shouldPollPostStatus(post) : false;
    const poll = usePoll(
        POLL_INTERVAL_MS,
        { only: ['post', 'stats'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (!active) {
            return;
        }

        poll.start();

        return () => poll.stop();
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- poll identity is stable per Inertia; active owns the polling lifecycle
    }, [active]);
}

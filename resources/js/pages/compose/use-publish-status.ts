import { useHttp, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { retry as retryRoute } from '@/routes/posts/targets';

import { anyTargetActive } from './publish-status';
import type { PostView } from './types';

const POLL_INTERVAL_MS = 3000;

type RetryResponse = { post: PostView };

type UsePublishStatus = {
    /** The page's current `post` prop (re-supplied by Inertia on each poll). */
    pagePost: PostView | null;
};

/**
 * Holds a live publish-status snapshot, separate from the editor reducer so
 * polling/refreshes never clobber in-progress edits. The snapshot is the most
 * recent of: the Inertia `post` prop (refreshed by `usePoll`), a mutation
 * response fed via `applyServerPost`, or a retry response.
 */
export function usePublishStatus({ pagePost }: UsePublishStatus) {
    const [snapshot, setSnapshot] = useState<PostView | null>(pagePost);
    const [retryingIds, setRetryingIds] = useState<ReadonlySet<string>>(
        () => new Set(),
    );
    const http = useHttp<Record<string, never>, RetryResponse>({});

    // Adopt the freshest page `post` prop (Inertia replaces it on each poll
    // reload). Mutation responses also flow in via `applyServerPost`; whichever
    // arrives last wins, which is correct because both reflect server truth.
    useEffect(() => {
        if (pagePost) {
            setSnapshot(pagePost);
        }
    }, [pagePost]);

    const active = snapshot ? anyTargetActive(snapshot.targets) : false;

    // Poll the page (only the `post` prop) while any target is in motion. The
    // returned `start`/`stop` are unused — `usePoll` auto-cleans on unmount and
    // we gate by toggling the interval below.
    const poll = usePoll(
        POLL_INTERVAL_MS,
        { only: ['post'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (active) {
            poll.start();

            return () => poll.stop();
        }
        poll.stop();
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- poll identity is stable per Inertia; gating on `active` is intentional
    }, [active]);

    /** Adopt the server's post after a publish/queue/schedule mutation. */
    function applyServerPost(post: PostView) {
        setSnapshot(post);
    }

    /** Re-dispatch a single failed target, then adopt the response. */
    async function retry(targetId: string) {
        if (!snapshot || retryingIds.has(targetId)) {
            return;
        }
        setRetryingIds((prev) => new Set(prev).add(targetId));
        try {
            const result = await http.post(
                retryRoute({
                    post: snapshot.id,
                    target: targetId,
                }).url,
                { onNetworkError: () => undefined },
            );
            setSnapshot(result.post);
        } finally {
            setRetryingIds((prev) => {
                const next = new Set(prev);
                next.delete(targetId);

                return next;
            });
        }
    }

    return {
        snapshot,
        retryingIds,
        applyServerPost,
        retry,
    };
}

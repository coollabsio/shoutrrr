import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import {
    applyOptimisticSubmit,
    type OptimisticSubmit,
} from '@/lib/compose/publish-status';
import { retry as retryRoute } from '@/routes/posts/targets';
import type { PostView } from '@/types/compose';

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

    /** Adopt the server's post after a publish/queue/schedule mutation. */
    function applyServerPost(post: PostView) {
        setSnapshot(post);
    }

    /**
     * Optimistically flip the current snapshot to its in-flight state the
     * instant the user submits, so the status chips react before the request
     * resolves. Returns a `revert` that restores the pre-submit snapshot — call
     * it on request failure; on success the server post (or poll) supersedes it.
     */
    function applyOptimistic(optimistic: OptimisticSubmit): () => void {
        let prior: PostView | null = null;
        setSnapshot((current) => {
            prior = current;

            return current ? applyOptimisticSubmit(current, optimistic) : null;
        });

        return () => setSnapshot(prior);
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
        applyOptimistic,
        retry,
    };
}

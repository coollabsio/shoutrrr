import { useHttp } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import PostController from '@/actions/App/Http/Controllers/Posts/PostController';

import {
    buildPutBody,
    type ComposerAction,
    type ComposerState,
} from './composer-state';
import type { PostView } from './types';

const DEBOUNCE_MS = 5000;

type SaveResponse = { post: PostView };

type UseAutosave = {
    state: ComposerState;
    accountIds: string[];
    dispatch: (action: ComposerAction) => void;
};

/**
 * Lazy POST→PUT autosave. Returns a `flush` callback to force an immediate save
 * (called on blur, visibility change, destination change, and submit).
 *
 * useHttp verbs take NO inline data — the request body is the hook's data. We
 * inject the dynamic payload via `transform()`, which runs at submit time (so it
 * always reflects the latest reducer state, avoiding React state-timing bugs).
 */
export function useAutosave({ state, accountIds, dispatch }: UseAutosave) {
    // TForm must satisfy FormDataType; the hook's own data is unused (we submit
    // via transform), so Record<string, never> is the minimal valid shape.
    const http = useHttp<Record<string, never>, SaveResponse>({});
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const inFlight = useRef(false);

    /**
     * Create the draft post (POST). Shared by the autosave create-branch and by
     * `ensurePost`, which must create a draft even when the user hasn't typed
     * (e.g. a media-first upload). Returns the new post id.
     */
    async function createPost(): Promise<string> {
        http.transform(() => ({
            base_text: state.baseText,
            destination: state.destination,
        }));
        const created = await http.post(PostController.store().url, {
            onNetworkError: () => dispatch({ type: 'saveFailedOffline' }),
        });
        dispatch({
            type: 'setPostId',
            postId: created.post.id,
            updatedAt: created.post.updated_at,
        });
        dispatch({ type: 'saveSucceeded', post: created.post });

        return created.post.id;
    }

    /**
     * Guarantee a persisted post id before a dependent action (e.g. media
     * upload). If a draft already exists, returns its id immediately; otherwise
     * creates one unconditionally, regardless of saveState.
     */
    async function ensurePost(): Promise<string> {
        if (state.postId !== null) {
            return state.postId;
        }
        if (inFlight.current) {
            return '';
        }
        inFlight.current = true;
        dispatch({ type: 'saveStarted' });
        try {
            return await createPost();
        } finally {
            inFlight.current = false;
        }
    }

    async function save() {
        if (inFlight.current || state.saveState !== 'dirty') {
            return;
        }
        inFlight.current = true;
        dispatch({ type: 'saveStarted' });

        try {
            if (state.postId === null) {
                await createPost();

                return;
            }

            http.transform(() => buildPutBody(state, accountIds));
            await http.put(PostController.update(state.postId).url, {
                // onSuccess's first arg is the parsed response body (TResponse).
                onSuccess: (data) =>
                    dispatch({ type: 'saveSucceeded', post: data.post }),
                // onHttpException's response.data is typed `string` but may arrive
                // parsed at runtime — handle both.
                onHttpException: (response) => {
                    if (response.status !== 409) {
                        return;
                    }
                    const raw = response.data;
                    const body = (
                        typeof raw === 'string' ? JSON.parse(raw) : raw
                    ) as SaveResponse;
                    dispatch({ type: 'saveFailedStale', post: body.post });
                },
                onNetworkError: () => dispatch({ type: 'saveFailedOffline' }),
            });
        } finally {
            inFlight.current = false;
        }
    }

    function flush() {
        if (timer.current) {
            clearTimeout(timer.current);
            timer.current = null;
        }
        void save();
    }

    // Debounce while dirty. `save` is intentionally re-created each render so the
    // timer fires with the latest state; deps list the fields that should reset
    // the timer. If oxlint flags react-hooks deps here, add an
    // `// oxlint-disable-next-line react-hooks/exhaustive-deps` directive — the
    // omission is deliberate.
    useEffect(() => {
        if (state.saveState !== 'dirty') {
            return;
        }
        if (timer.current) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => void save(), DEBOUNCE_MS);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [
        state.saveState,
        state.baseText,
        state.destination,
        state.overrideByAccount,
        state.autoSplitByAccount,
        state.media,
    ]);

    // Flush on tab-hide.
    useEffect(() => {
        function onHide() {
            if (document.visibilityState === 'hidden') {
                flush();
            }
        }
        document.addEventListener('visibilitychange', onHide);

        return () => document.removeEventListener('visibilitychange', onHide);
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [state]);

    return { flush, ensurePost, processing: http.processing };
}

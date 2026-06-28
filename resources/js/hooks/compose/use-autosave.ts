import { useHttp } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import PostController from '@/actions/App/Http/Controllers/Posts/PostController';
import {
    buildPutBody,
    type ComposerAction,
    composerHasContent,
    type ComposerState,
} from '@/lib/compose/composer-state';
import type { PostView } from '@/types/compose';

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
    // The save currently in flight, tracked as a promise (not a boolean) so a
    // forced `flush` can await it before starting the next save — this is what
    // lets publishing wait for the draft (media, targets) to be persisted first.
    const inFlight = useRef<Promise<void> | null>(null);
    // Latest known post id, mirrored in a ref so a concurrent `ensurePost` can
    // read it after awaiting an in-flight create (the reducer `state` closure is
    // stale inside an async call).
    const postIdRef = useRef<string | null>(state.postId);
    postIdRef.current = state.postId;
    // Latest server-acknowledged updated_at, mirrored in a ref so a save queued
    // behind an in-flight one sends the freshest `expected_updated_at` rather
    // than a stale React-closure snapshot — otherwise a single user's own
    // chained saves trip the optimistic-concurrency check and 409 against
    // themselves. Updated synchronously on every server response below, and via
    // the effect for external changes (hydrate / conflict resolution).
    const baselineRef = useRef<string | null>(state.baselineUpdatedAt);

    /**
     * Create the draft post (POST). Shared by the autosave create-branch and by
     * `ensurePost`, which must create a draft even when the user hasn't typed
     * (e.g. a media-first upload). Returns the new post id.
     */
    async function createPost(): Promise<string> {
        http.transform(() => ({
            segments: state.segments,
            mentions: state.mentions,
            destination: state.destination,
        }));
        const created = await http.post(PostController.store().url, {
            onNetworkError: () => dispatch({ type: 'saveFailedOffline' }),
        });
        postIdRef.current = created.post.id;
        baselineRef.current = created.post.updated_at;
        dispatch({
            type: 'setPostId',
            postId: created.post.id,
            updatedAt: created.post.updated_at,
        });
        dispatch({ type: 'saveSucceeded', post: created.post });

        return created.post.id;
    }

    /**
     * Persist the current reducer snapshot. No guards — callers coordinate via
     * `inFlight`. A brand-new post is created (POST); thereafter edits go via
     * PUT. `transform()` reads `state` at submit time so it captures the freshest
     * snapshot, including media added moments before a publish.
     */
    async function persist(): Promise<void> {
        dispatch({ type: 'saveStarted' });

        const postId = postIdRef.current;
        if (postId === null) {
            await createPost();

            return;
        }

        // expected_updated_at comes from the ref (latest server version), not the
        // possibly-stale closure, so a save queued behind another never 409s.
        http.transform(() => ({
            ...buildPutBody(state, accountIds),
            expected_updated_at: baselineRef.current,
        }));
        await http.put(PostController.update(postId).url, {
            // onSuccess's first arg is the parsed response body (TResponse).
            onSuccess: (data) => {
                baselineRef.current = data.post.updated_at;
                dispatch({ type: 'saveSucceeded', post: data.post });
            },
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
    }

    /**
     * Run `work` serialized behind any in-flight save, tracking it on `inFlight`
     * so the debounce, `flush`, and `ensurePost` wait rather than overlap. The
     * tracked promise never rejects (failures are surfaced via the save
     * callbacks → reducer), so awaiting it never blocks a subsequent publish.
     */
    function enqueueSave(work: () => Promise<unknown>): Promise<void> {
        const prior = inFlight.current ?? Promise.resolve();
        const tracked: Promise<void> = prior
            .then(work, work)
            .then(
                () => undefined,
                () => undefined,
            )
            .finally(() => {
                if (inFlight.current === tracked) {
                    inFlight.current = null;
                }
            });
        inFlight.current = tracked;

        return tracked;
    }

    /**
     * Guarantee a persisted post id before a dependent action (e.g. media
     * upload). If a draft already exists, returns its id immediately; otherwise
     * creates one (serialized behind any in-flight save), regardless of
     * saveState.
     */
    async function ensurePost(): Promise<string> {
        if (postIdRef.current !== null) {
            return postIdRef.current;
        }
        if (inFlight.current) {
            await inFlight.current;

            return postIdRef.current ?? '';
        }
        dispatch({ type: 'saveStarted' });
        await enqueueSave(createPost);

        return postIdRef.current ?? '';
    }

    /** Debounced autosave: only when dirty and nothing already in flight. */
    async function save() {
        if (inFlight.current || state.saveState !== 'dirty') {
            return;
        }
        // An empty composer with no draft yet has nothing worth a POST — a
        // destination change alone must not create a blank draft. Media-first
        // uploads still create one via `ensurePost` (media counts as content).
        if (postIdRef.current === null && !composerHasContent(state)) {
            dispatch({ type: 'saveSkippedEmpty' });

            return;
        }
        await enqueueSave(persist);
    }

    /**
     * Force an immediate, awaitable save. Resolves only once the latest edits
     * are durably persisted, so callers (e.g. publishing) can rely on media and
     * targets being saved server-side before the publish request fires.
     */
    async function flush(): Promise<void> {
        if (timer.current) {
            clearTimeout(timer.current);
            timer.current = null;
        }
        // Wait out any in-flight save so the forced save reflects the latest
        // snapshot (e.g. media added just before clicking Publish).
        if (inFlight.current) {
            await inFlight.current;
        }
        if (state.saveState === 'saved' || state.saveState === 'idle') {
            return;
        }
        // Same empty-draft guard as the debounced path: forcing a save (e.g. on
        // a destination change or blur) must not create a blank draft either.
        if (postIdRef.current === null && !composerHasContent(state)) {
            dispatch({ type: 'saveSkippedEmpty' });

            return;
        }
        await enqueueSave(persist);
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
        state.segments,
        state.mentions,
        state.destination,
        state.overrideByAccount,
        state.autoSplitByAccount,
        state.media,
    ]);

    // Keep the version ref in step with externally-driven baseline changes
    // (initial hydrate, conflict resolution) that don't flow through a save here.
    useEffect(() => {
        baselineRef.current = state.baselineUpdatedAt;
    }, [state.baselineUpdatedAt]);

    // Flush on tab-hide.
    useEffect(() => {
        function onHide() {
            if (document.visibilityState === 'hidden') {
                void flush();
            }
        }
        document.addEventListener('visibilitychange', onHide);

        return () => document.removeEventListener('visibilitychange', onHide);
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [state]);

    return { flush, ensurePost, processing: http.processing };
}

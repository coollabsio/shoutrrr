/** @vitest-environment jsdom */

import { useHttp } from '@inertiajs/react';
import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    composerReducer,
    initialComposerState,
    type ComposerState,
} from '@/lib/compose/composer-state';
import type { PostView } from '@/types/compose';

import { AUTOSAVE_DEBOUNCE_MS, useAutosave } from '../use-autosave';

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
}));

vi.mock('@/actions/App/Http/Controllers/Posts/PostController', () => ({
    default: {
        store: () => ({ url: '/posts' }),
        update: (id: string) => ({ url: `/posts/${id}` }),
    },
}));

const post: PostView = {
    id: 'post-1',
    base_text: 'Hello',
    segments: ['Hello'],
    status: 'draft',
    published_at: null,
    updated_at: '2026-07-17T10:00:00+00:00',
    scheduled_at: null,
    auto_repost: null,
    destination: { kind: 'all', id: null },
    targets: [],
    media: [],
};

const transform = vi.fn();
const httpPost = vi.fn();
const httpPut = vi.fn();

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let flushRef: (() => Promise<void>) | null = null;

function draftState(overrides: Partial<ComposerState> = {}): ComposerState {
    return {
        ...initialComposerState(),
        saveState: 'dirty',
        segments: ['Hello'],
        ...overrides,
    };
}

function Harness({
    state,
    onSaved,
}: {
    state: ComposerState;
    onSaved: () => void;
}) {
    const { flush } = useAutosave({
        state,
        accountIds: [],
        dispatch: vi.fn(),
        onSaved,
    });
    flushRef = flush;

    return null;
}

beforeEach(() => {
    transform.mockReset();
    httpPost.mockReset().mockResolvedValue({ post });
    httpPut.mockReset().mockImplementation((_url, opts) => {
        opts?.onSuccess?.({ post });

        return Promise.resolve();
    });
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post: httpPost,
        put: httpPut,
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
    container = document.createElement('div');
    root = createRoot(container);
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    container = null;
    flushRef = null;
    vi.clearAllMocks();
});

describe('autosave debounce', () => {
    it('waits 500ms after draft edits before saving', () => {
        expect(AUTOSAVE_DEBOUNCE_MS).toBe(500);
    });
});

describe('useAutosave onSaved', () => {
    it('fires after a successful create (POST)', async () => {
        const onSaved = vi.fn();
        act(() => {
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: null }),
                    onSaved,
                }),
            );
        });

        await act(async () => {
            await flushRef?.();
        });

        expect(httpPost).toHaveBeenCalledOnce();
        expect(onSaved).toHaveBeenCalledOnce();
    });

    it('fires after a successful update (PUT)', async () => {
        const onSaved = vi.fn();
        act(() => {
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: 'post-1',
                        baselineUpdatedAt: post.updated_at,
                    }),
                    onSaved,
                }),
            );
        });

        await act(async () => {
            await flushRef?.();
        });

        expect(httpPut).toHaveBeenCalledOnce();
        expect(onSaved).toHaveBeenCalledOnce();
    });
});

describe('autosave debounce reset on placement-only changes', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('resets the timer for a placements-only edit mid-window, so the latest placements are saved (not the stale ones)', async () => {
        const onSaved = vi.fn();
        const baseState = draftState({
            postId: 'post-1',
            baselineUpdatedAt: post.updated_at,
        });

        act(() => {
            root?.render(createElement(Harness, { state: baseState, onSaved }));
        });

        // Partway through the original debounce window — not enough to fire.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(250);
        });
        expect(httpPut).not.toHaveBeenCalled();

        // A placements-only edit lands mid-window (e.g. dragging media between
        // segments). saveState stays 'dirty'; nothing else in the currently
        // tracked deps changes — only `placements` does.
        const movedState = composerReducer(baseState, {
            type: 'moveMediaToSegment',
            mediaId: 'media-99',
            segmentRef: '__head__',
        });
        expect(movedState.placements).not.toBe(baseState.placements);
        expect(movedState.saveState).toBe('dirty');

        act(() => {
            root?.render(
                createElement(Harness, { state: movedState, onSaved }),
            );
        });

        // Past the ORIGINAL deadline (250ms + 250ms = 500ms elapsed). If the
        // timer was correctly reset by the placement change, nothing has
        // saved yet — the original timer must have been cleared.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(250);
        });
        expect(httpPut).not.toHaveBeenCalled();

        // Past the NEW deadline (250ms mid-window + a fresh 500ms window from
        // the reset).
        await act(async () => {
            await vi.advanceTimersByTimeAsync(250);
        });
        expect(httpPut).toHaveBeenCalledOnce();

        // The saved payload must reflect the latest placements, not the stale
        // snapshot captured when the original timer was armed.
        const lastTransformCall = transform.mock.calls.at(-1) as
            | [() => { placements: unknown }]
            | undefined;
        const body = lastTransformCall?.[0]();
        expect(body?.placements).toContainEqual({
            media_id: 'media-99',
            segment_ref: '__head__',
            position: 0,
        });
    });
});

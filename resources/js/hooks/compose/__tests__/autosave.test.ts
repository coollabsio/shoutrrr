/** @vitest-environment jsdom */

import { useHttp } from '@inertiajs/react';
import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
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

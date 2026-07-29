/** @vitest-environment jsdom */

import { usePoll } from '@inertiajs/react';
import { act, createElement, Fragment } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useLiveProps, useLivePropsPoll } from '@/hooks/use-live-props';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePoll: vi.fn(() => ({ start: vi.fn(), stop: vi.fn() })),
    router: { reload },
}));

const CHROME = ['notifications', 'shell.unreadReplies'];
const PAGE = ['replies'];
let root: Root | null = null;

function Driver() {
    useLivePropsPoll({ only: CHROME, intervalMs: 60_000 });

    return null;
}

function Page() {
    useLiveProps({ only: PAGE, reset: PAGE });

    return null;
}

function mount(withPage: boolean) {
    act(() => {
        root?.render(
            createElement(
                Fragment,
                null,
                createElement(Driver),
                withPage ? createElement(Page) : null,
            ),
        );
    });
}

function setVisibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        get: () => state,
    });
}

/** Options the poll would send on its next tick. */
function pollOptions() {
    const build = vi.mocked(usePoll).mock.calls[0]?.[1] as () => {
        only: string[];
        reset: string[];
        showProgress: boolean;
        onStart: () => void;
    };

    return build();
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-28T10:00:00Z'));
    setVisibility('visible');
    root = createRoot(document.createElement('div'));
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    vi.useRealTimers();
    vi.clearAllMocks();
});

describe('useLivePropsPoll', () => {
    it('polls the chrome props without overlapping requests', () => {
        mount(false);

        expect(usePoll).toHaveBeenCalledWith(60_000, expect.any(Function), {
            mode: 'rest',
        });
        expect(pollOptions()).toMatchObject({
            only: CHROME,
            reset: [],
            showProgress: false,
        });
    });

    it('refreshes as soon as the tab is looked at again', () => {
        mount(false);

        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: CHROME, showProgress: false }),
        );
    });

    it('stays quiet while the tab is in the background', () => {
        mount(false);
        setVisibility('hidden');

        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });

        expect(reload).not.toHaveBeenCalled();
    });

    it('skips a focus refresh that follows a request it would duplicate', () => {
        mount(false);
        pollOptions().onStart();

        act(() => {
            window.dispatchEvent(new Event('focus'));
        });

        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(6_000);
        act(() => {
            window.dispatchEvent(new Event('focus'));
        });

        expect(reload).toHaveBeenCalledOnce();
    });

    it('stops listening once the chrome unmounts', () => {
        mount(false);
        act(() => root?.unmount());
        root = null;

        act(() => {
            window.dispatchEvent(new Event('focus'));
        });

        expect(reload).not.toHaveBeenCalled();
    });
});

describe('useLiveProps', () => {
    it('folds page props into the one request instead of adding another', () => {
        mount(true);

        expect(usePoll).toHaveBeenCalledOnce();
        expect(pollOptions()).toMatchObject({
            only: [...CHROME, ...PAGE],
            reset: PAGE,
        });
    });

    it('drops the page props again once the page unmounts', () => {
        mount(true);
        mount(false);

        expect(pollOptions()).toMatchObject({ only: CHROME, reset: [] });
    });
});

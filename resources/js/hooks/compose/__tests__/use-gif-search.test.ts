/** @vitest-environment jsdom */

import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { GifCatalog } from '@/types/gifs';

import { useGifSearch } from '../use-gif-search';

function page(slugs: string[], hasNext = false) {
    return {
        items: slugs.map((slug) => ({
            id: slug,
            slug,
            catalog: 'gif',
            title: slug,
            preview: {
                url: `https://cdn.klipy.com/${slug}.gif`,
                mime: 'image/gif',
                width: 120,
                height: 90,
                bytes: 1000,
            },
            variants: [],
        })),
        has_next: hasNext,
    };
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify(page(['a', 'b'])))),
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('useGifSearch', () => {
    it('fetches trending on mount when enabled', async () => {
        const { result } = renderHook(() => useGifSearch('gif', true));

        await waitFor(() => expect(result.current.items).toHaveLength(2));
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('fetches nothing while disabled', async () => {
        renderHook(() => useGifSearch('gif', false));

        await act(async () => {
            vi.advanceTimersByTime(500);
        });

        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('debounces query changes into a single request', async () => {
        const { result } = renderHook(() => useGifSearch('gif', true));
        await waitFor(() => expect(result.current.items).toHaveLength(2));

        act(() => {
            result.current.setQuery('t');
            result.current.setQuery('th');
            result.current.setQuery('thanks');
        });

        await act(async () => {
            vi.advanceTimersByTime(300);
        });

        // One trending call on mount + one debounced search.
        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledTimes(2));
    });

    it('appends the next page on loadMore', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(JSON.stringify(page(['a'], true))),
                )
                .mockResolvedValueOnce(
                    new Response(JSON.stringify(page(['b']))),
                ),
        );

        const { result } = renderHook(() => useGifSearch('gif', true));
        await waitFor(() => expect(result.current.items).toHaveLength(1));

        act(() => result.current.loadMore());

        await waitFor(() => expect(result.current.items).toHaveLength(2));
        expect(result.current.hasNext).toBe(false);
    });

    it('surfaces an error and recovers on retry', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(new Response('nope', { status: 502 }))
                .mockResolvedValueOnce(
                    new Response(JSON.stringify(page(['a']))),
                ),
        );

        const { result } = renderHook(() => useGifSearch('gif', true));

        await waitFor(() => expect(result.current.error).not.toBeNull());

        act(() => result.current.retry());

        await waitFor(() => expect(result.current.items).toHaveLength(1));
        expect(result.current.error).toBeNull();
    });

    it('ignores a stale response that resolves after a fresher one', async () => {
        // The mount request (trending, id 1) is left pending. A query change
        // fires a second request (id 2) which resolves first. If the mount
        // request's response were applied afterwards without a supersession
        // guard, it would clobber the fresher 'b' result with the stale 'a'.
        let resolveMount!: (response: Response) => void;
        let resolveQuery!: (response: Response) => void;
        const mountResponse = new Promise<Response>((resolve) => {
            resolveMount = resolve;
        });
        const queryResponse = new Promise<Response>((resolve) => {
            resolveQuery = resolve;
        });

        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockReturnValueOnce(mountResponse)
                .mockReturnValueOnce(queryResponse),
        );

        const { result } = renderHook(() => useGifSearch('gif', true));

        act(() => result.current.setQuery('cats'));
        await act(async () => {
            vi.advanceTimersByTime(300);
        });
        expect(globalThis.fetch).toHaveBeenCalledTimes(2);

        // Fresher (query) request resolves first.
        resolveQuery(new Response(JSON.stringify(page(['b']))));
        await waitFor(() =>
            expect(result.current.items.map((item) => item.slug)).toEqual([
                'b',
            ]),
        );

        // Stale (mount) request resolves late; it must be ignored.
        resolveMount(new Response(JSON.stringify(page(['a']))));
        await act(async () => {
            vi.advanceTimersByTime(0);
        });

        expect(result.current.items.map((item) => item.slug)).toEqual(['b']);
    });

    it('never mixes a stale page with a new query after loadMore', async () => {
        const calls: string[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string) => {
                calls.push(url);

                return Promise.resolve(
                    new Response(JSON.stringify(page(['x'], true))),
                );
            }),
        );

        const { result } = renderHook(() => useGifSearch('gif', true));
        await waitFor(() => expect(calls).toHaveLength(1));

        act(() => result.current.loadMore());
        await waitFor(() => expect(calls).toHaveLength(2));

        act(() => result.current.setQuery('dogs'));
        await act(async () => {
            vi.advanceTimersByTime(300);
        });
        await waitFor(() => expect(calls.length).toBeGreaterThanOrEqual(3));

        // The pre-reset page (2) must never be requested alongside the new query.
        const badCall = calls.some((url) => {
            const params = new URL(url, 'http://example.test').searchParams;

            return params.get('page') === '2' && params.get('q') === 'dogs';
        });
        expect(badCall).toBe(false);

        const last = new URL(calls[calls.length - 1], 'http://example.test');
        expect(last.searchParams.get('page')).toBe('1');
        expect(last.searchParams.get('q')).toBe('dogs');
    });

    it('never mixes a stale page with a new catalog after loadMore', async () => {
        const calls: string[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string) => {
                calls.push(url);

                return Promise.resolve(
                    new Response(JSON.stringify(page(['x'], true))),
                );
            }),
        );

        const { result, rerender } = renderHook(
            ({ catalog }) => useGifSearch(catalog, true),
            { initialProps: { catalog: 'gif' as GifCatalog } },
        );
        await waitFor(() => expect(calls).toHaveLength(1));

        act(() => result.current.loadMore());
        await waitFor(() => expect(calls).toHaveLength(2));

        rerender({ catalog: 'sticker' });
        await waitFor(() => expect(calls.length).toBeGreaterThanOrEqual(3));

        // The old catalog's page-2 request must never be replayed for the new
        // catalog.
        const badCall = calls.some((url) => {
            const parsed = new URL(url, 'http://example.test');

            return (
                parsed.pathname.includes('/sticker') &&
                parsed.searchParams.get('page') === '2'
            );
        });
        expect(badCall).toBe(false);

        const last = new URL(calls[calls.length - 1], 'http://example.test');
        expect(last.pathname).toContain('/sticker');
        expect(last.searchParams.get('page')).toBe('1');
    });

    it('clears isLoading when disabled while a request is in flight', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise<Response>(() => {})),
        );

        const { result, rerender } = renderHook(
            ({ enabled }) => useGifSearch('gif', enabled),
            { initialProps: { enabled: true } },
        );

        await waitFor(() => expect(result.current.isLoading).toBe(true));

        rerender({ enabled: false });

        expect(result.current.isLoading).toBe(false);
    });

    it('aborts the in-flight request on unmount', async () => {
        let capturedSignal: AbortSignal | undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn((_url: string, init?: RequestInit) => {
                capturedSignal = init?.signal ?? undefined;

                // Never resolves — the assertion only holds if the request is
                // cancelled rather than left dangling.
                return new Promise<Response>(() => {});
            }),
        );

        const { unmount } = renderHook(() => useGifSearch('gif', true));

        await waitFor(() => expect(capturedSignal).toBeDefined());
        expect(capturedSignal?.aborted).toBe(false);

        unmount();

        expect(capturedSignal?.aborted).toBe(true);
    });
});

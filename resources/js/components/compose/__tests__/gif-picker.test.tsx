import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { GifPicker } from '../gif-picker';

/**
 * Node 22+'s built-in global `localStorage` shadows jsdom's (it `in global`
 * before jsdom's window is populated, so vitest's jsdom environment skips
 * copying jsdom's real implementation over it — see vitest's `getWindowKeys`).
 * Accessing the bare global then throws instead of storing anything. Stub a
 * minimal in-memory implementation for the duration of these tests rather
 * than touching shared vitest config for one suite.
 */
function createMemoryStorage(): Storage {
    const store = new Map<string, string>();

    return {
        get length() {
            return store.size;
        },
        clear: () => store.clear(),
        getItem: (key: string) =>
            store.has(key) ? (store.get(key) ?? null) : null,
        setItem: (key: string, value: string) => {
            store.set(key, String(value));
        },
        removeItem: (key: string) => {
            store.delete(key);
        },
        key: (index: number) => Array.from(store.keys())[index] ?? null,
    };
}

/**
 * A controllable stand-in for the browser's IntersectionObserver. Records
 * every instance created (there's one per GifPicker mount, observing the
 * scroll sentinel) so a test can drive `trigger()` to simulate the sentinel
 * entering/leaving the viewport, independent of real layout in jsdom.
 */
class MockIntersectionObserver {
    static instances: MockIntersectionObserver[] = [];
    callback: IntersectionObserverCallback;
    disconnect = vi.fn();

    constructor(callback: IntersectionObserverCallback) {
        this.callback = callback;
        MockIntersectionObserver.instances.push(this);
    }

    observe() {}
    unobserve() {}

    trigger(isIntersecting: boolean) {
        this.callback(
            [{ isIntersecting } as IntersectionObserverEntry],
            this as unknown as IntersectionObserver,
        );
    }
}

function payload(slugs: string[], hasNext = false) {
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
            variants: [
                {
                    url: `https://cdn.klipy.com/${slug}.gif`,
                    mime: 'image/gif',
                    width: 320,
                    height: 240,
                    bytes: 40000,
                },
            ],
        })),
        has_next: hasNext,
    };
}

function pageParam(url: string): string | null {
    return new URL(url, 'http://localhost').searchParams.get('page');
}

beforeEach(() => {
    MockIntersectionObserver.instances = [];
    vi.stubGlobal('localStorage', createMemoryStorage());
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async () => new Response(JSON.stringify(payload(['yay', 'wow']))),
        ),
    );
    vi.stubGlobal('IntersectionObserver', MockIntersectionObserver);
});

afterEach(() => vi.unstubAllGlobals());

describe('GifPicker', () => {
    it('renders a tile per result', async () => {
        render(<GifPicker onSelect={vi.fn()} />);

        await waitFor(() =>
            expect(
                screen.getAllByRole('button', { name: /insert/i }),
            ).toHaveLength(2),
        );
    });

    it('calls onSelect once when a tile is clicked', async () => {
        const onSelect = vi.fn();
        render(<GifPicker onSelect={onSelect} />);

        const tiles = await screen.findAllByRole('button', { name: /insert/i });
        fireEvent.click(tiles[0]);

        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(onSelect.mock.calls[0][0].slug).toBe('yay');
    });

    it('toggles a favourite without inserting', async () => {
        const onSelect = vi.fn();
        render(<GifPicker onSelect={onSelect} />);

        const hearts = await screen.findAllByRole('button', {
            name: /favourite/i,
        });
        fireEvent.click(hearts[0]);

        expect(onSelect).not.toHaveBeenCalled();
        expect(localStorage.getItem('shoutrrr:gifs:favorites')).toContain(
            'yay',
        );
    });

    it('shows an empty state when there are no results', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({ items: [], has_next: false }),
                    ),
            ),
        );

        render(<GifPicker onSelect={vi.fn()} />);

        expect(await screen.findByText(/no gifs found/i)).toBeInTheDocument();
    });

    it('shows an error state with a retry', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('nope', { status: 502 })),
        );

        render(<GifPicker onSelect={vi.fn()} />);

        expect(
            await screen.findByRole('button', { name: /try again/i }),
        ).toBeInTheDocument();
    });

    it('switches catalogs', async () => {
        render(<GifPicker onSelect={vi.fn()} />);
        await screen.findAllByRole('button', { name: /insert/i });

        fireEvent.click(screen.getByRole('tab', { name: /stickers/i }));

        await waitFor(() =>
            expect(
                (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.some(
                    ([url]) => String(url).includes('sticker'),
                ),
            ).toBe(true),
        );
    });

    it('carries the typed query over to the newly selected catalog', async () => {
        render(<GifPicker onSelect={vi.fn()} />);
        await screen.findAllByRole('button', { name: /insert/i });

        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'cat' },
        });
        await waitFor(() =>
            expect(
                (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.some(
                    ([url]) =>
                        String(url).includes('gif') &&
                        String(url).includes('q=cat'),
                ),
            ).toBe(true),
        );

        fireEvent.click(screen.getByRole('tab', { name: /clips/i }));

        await waitFor(() =>
            expect(
                (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.some(
                    ([url]) =>
                        String(url).includes('clip') &&
                        String(url).includes('q=cat'),
                ),
            ).toBe(true),
        );
    });

    it('labels the search box and empty state after the active catalog', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({ items: [], has_next: false }),
                    ),
            ),
        );

        render(<GifPicker onSelect={vi.fn()} />);

        expect(
            screen.getByRole('searchbox', { name: 'Search GIFs' }),
        ).toBeInTheDocument();
        expect(await screen.findByText('No GIFs found.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: /stickers/i }));

        expect(
            screen.getByRole('searchbox', { name: 'Search stickers' }),
        ).toBeInTheDocument();
        expect(
            await screen.findByText('No stickers found.'),
        ).toBeInTheDocument();
    });

    it('continues to the next page automatically when a settled page does not fill the viewport', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) => {
                const page = pageParam(url);

                if (page === '2') {
                    return new Response(
                        JSON.stringify(payload(['c', 'd'], true)),
                    );
                }
                if (page === '3') {
                    return new Response(JSON.stringify(payload([], false)));
                }

                return new Response(JSON.stringify(payload(['a', 'b'], true)));
            }),
        );

        render(<GifPicker onSelect={vi.fn()} />);
        await screen.findAllByRole('button', { name: /insert/i });

        // Simulate the sentinel being visible from the start (a short first
        // page that doesn't overflow the 420px scroll area) — this is the one
        // crossing event the observer ever sees; everything after page 1 must
        // come from the settle-effect noticing continued room, not from a
        // second crossing.
        MockIntersectionObserver.instances[0]?.trigger(true);

        // `isLoading` flips back to `false` in its own render, one tick after
        // the render that applies the new items — so the page-3 request the
        // settle-effect fires can land after the DOM already shows 4 tiles.
        // Poll on the fetch calls themselves rather than only on tile count.
        await waitFor(() => {
            const pagesRequested = (
                globalThis.fetch as ReturnType<typeof vi.fn>
            ).mock.calls.map(([url]) => pageParam(String(url)));
            expect(pagesRequested).toEqual(['1', '2', '3']);
        });

        expect(screen.getAllByRole('button', { name: /insert/i })).toHaveLength(
            4,
        );
    });

    it('shows a spinner beside the existing grid while the next page loads', async () => {
        let releasePageTwo!: () => void;
        const pageTwoGate = new Promise<void>((resolve) => {
            releasePageTwo = resolve;
        });

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) => {
                if (pageParam(url) === '2') {
                    await pageTwoGate;

                    return new Response(
                        JSON.stringify(payload(['c', 'd'], false)),
                    );
                }

                return new Response(JSON.stringify(payload(['a', 'b'], true)));
            }),
        );

        render(<GifPicker onSelect={vi.fn()} />);
        await screen.findAllByRole('button', { name: /insert/i });

        // The first page never shows the spinner — it renders the skeleton.
        expect(
            screen.queryByRole('status', { name: /loading more/i }),
        ).not.toBeInTheDocument();

        MockIntersectionObserver.instances[0]?.trigger(true);

        // Page 2 is in flight and page 1's tiles are still on screen.
        expect(
            await screen.findByRole('status', { name: /loading more/i }),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /insert/i })).toHaveLength(
            2,
        );

        releasePageTwo();

        await waitFor(() =>
            expect(
                screen.getAllByRole('button', { name: /insert/i }),
            ).toHaveLength(4),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('status', { name: /loading more/i }),
            ).not.toBeInTheDocument(),
        );
    });

    it('does not loop forever when a page reports has_next but returns no items', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) => {
                const page = pageParam(url);

                if (page === '2') {
                    // A misbehaving API: claims more pages exist, but this one is empty.
                    return new Response(JSON.stringify(payload([], true)));
                }

                return new Response(JSON.stringify(payload(['a', 'b'], true)));
            }),
        );

        render(<GifPicker onSelect={vi.fn()} />);
        await screen.findAllByRole('button', { name: /insert/i });

        MockIntersectionObserver.instances[0]?.trigger(true);

        await waitFor(() =>
            expect(
                (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls
                    .length,
            ).toBe(2),
        );

        // Give a runaway effect loop a chance to fire before asserting it didn't.
        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(
            (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.length,
        ).toBe(2);
    });

    const junkDimensionCases: {
        label: string;
        preview: Record<string, unknown>;
    }[] = [
        {
            label: 'non-numeric width',
            preview: { width: 'not-a-number', height: 90 },
        },
        { label: 'missing width', preview: { height: 90 } },
        { label: 'zero width', preview: { width: 0, height: 90 } },
        { label: 'negative height', preview: { width: 120, height: -10 } },
    ];

    it.each(junkDimensionCases)(
        'renders a favourite with $label without breaking layout',
        async ({ preview }) => {
            const junkItem = {
                id: 'junk',
                slug: 'junk',
                catalog: 'gif',
                title: 'junk',
                preview: {
                    url: 'https://cdn.klipy.com/junk.gif',
                    mime: 'image/gif',
                    bytes: 1000,
                    ...preview,
                },
                variants: [
                    {
                        url: 'https://cdn.klipy.com/junk.gif',
                        mime: 'image/gif',
                        width: 320,
                        height: 240,
                        bytes: 40000,
                    },
                ],
            };
            localStorage.setItem(
                'shoutrrr:gifs:favorites',
                JSON.stringify([junkItem]),
            );

            render(<GifPicker onSelect={vi.fn()} />);

            const insertButton = await screen.findByRole('button', {
                name: /insert junk/i,
            });

            expect(insertButton.style.aspectRatio).not.toBe('NaN');
            expect(insertButton.style.aspectRatio).not.toBe('Infinity');
            expect(insertButton.style.aspectRatio).not.toBe('');
        },
    );
});

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

function payload(slugs: string[]) {
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
        has_next: false,
    };
}

beforeEach(() => {
    vi.stubGlobal('localStorage', createMemoryStorage());
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async () => new Response(JSON.stringify(payload(['yay', 'wow']))),
        ),
    );
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
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

    it('renders a favourite with junk dimensions without breaking layout', async () => {
        const junkItem = {
            id: 'junk',
            slug: 'junk',
            catalog: 'gif',
            title: 'junk',
            preview: {
                url: 'https://cdn.klipy.com/junk.gif',
                mime: 'image/gif',
                width: 'not-a-number',
                height: 90,
                bytes: 1000,
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
    });
});

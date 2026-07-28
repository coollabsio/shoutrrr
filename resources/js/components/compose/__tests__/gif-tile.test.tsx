import { fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { GifItem } from '@/types/gifs';

import { GifTile } from '../gif-tile';

/**
 * A controllable stand-in for the browser's IntersectionObserver, scoped to
 * one clip tile's own `<video>`. Lets a test drive `trigger()` to simulate
 * the tile entering/leaving the popover's viewport.
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

function clipItem(overrides: Partial<GifItem> = {}): GifItem {
    return {
        id: 'reaction',
        slug: 'reaction',
        catalog: 'clip',
        title: 'reaction',
        preview: {
            url: 'https://cdn.klipy.com/reaction.mp4',
            mime: 'video/mp4',
            width: 320,
            height: 240,
            bytes: 90000,
        },
        variants: [],
        ...overrides,
    };
}

function gifItem(preview: Partial<GifItem['preview']>): GifItem {
    return {
        id: 'junk',
        slug: 'junk',
        catalog: 'gif',
        title: 'junk',
        preview: {
            url: 'https://cdn.klipy.com/junk.gif',
            mime: 'image/gif',
            width: 120,
            height: 90,
            bytes: 1000,
            ...preview,
        },
        variants: [],
    };
}

let playSpy: ReturnType<typeof vi.spyOn>;
let pauseSpy: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    MockIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', MockIntersectionObserver);
    playSpy = vi
        .spyOn(HTMLMediaElement.prototype, 'play')
        .mockImplementation(() => Promise.resolve());
    pauseSpy = vi
        .spyOn(HTMLMediaElement.prototype, 'pause')
        .mockImplementation(() => {});
});

afterEach(() => {
    vi.unstubAllGlobals();
    playSpy.mockRestore();
    pauseSpy.mockRestore();
});

describe('GifTile — clip autoplay', () => {
    it('does not play a clip tile while it is not intersecting', () => {
        render(
            <GifTile
                item={clipItem()}
                isFavorite={false}
                onSelect={vi.fn()}
                onToggleFavorite={vi.fn()}
            />,
        );

        expect(playSpy).not.toHaveBeenCalled();
    });

    it('plays a clip tile once it starts intersecting, and pauses when it leaves', () => {
        render(
            <GifTile
                item={clipItem()}
                isFavorite={false}
                onSelect={vi.fn()}
                onToggleFavorite={vi.fn()}
            />,
        );

        const observer = MockIntersectionObserver.instances[0];
        expect(observer).toBeDefined();

        observer.trigger(true);
        expect(playSpy).toHaveBeenCalledTimes(1);
        expect(pauseSpy).not.toHaveBeenCalled();

        observer.trigger(false);
        expect(pauseSpy).toHaveBeenCalledTimes(1);
    });

    it('disconnects the intersection observer on unmount', () => {
        const { unmount } = render(
            <GifTile
                item={clipItem()}
                isFavorite={false}
                onSelect={vi.fn()}
                onToggleFavorite={vi.fn()}
            />,
        );

        const observer = MockIntersectionObserver.instances[0];
        unmount();

        expect(observer.disconnect).toHaveBeenCalledTimes(1);
    });

    it('never sets up an observer for a non-clip (image) tile', () => {
        render(
            <GifTile
                item={gifItem({})}
                isFavorite={false}
                onSelect={vi.fn()}
                onToggleFavorite={vi.fn()}
            />,
        );

        expect(MockIntersectionObserver.instances).toHaveLength(0);
    });
});

describe('GifTile — aspect-ratio guard branches', () => {
    const cases: { label: string; preview: Partial<GifItem['preview']> }[] = [
        {
            label: 'Infinity height',
            preview: { height: Number.POSITIVE_INFINITY },
        },
        { label: 'NaN width', preview: { width: Number.NaN } },
    ];

    it.each(cases)(
        'falls back to a usable aspect-ratio for $label',
        ({ preview }) => {
            const { getByRole } = render(
                <GifTile
                    item={gifItem(preview)}
                    isFavorite={false}
                    onSelect={vi.fn()}
                    onToggleFavorite={vi.fn()}
                />,
            );

            const insertButton = getByRole('button', { name: /insert junk/i });

            expect(insertButton.style.aspectRatio).not.toBe('NaN');
            expect(insertButton.style.aspectRatio).not.toBe('Infinity');
            expect(insertButton.style.aspectRatio).not.toBe('');
        },
    );
});

describe('GifTile — still image reveal', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('reveals an image that is already complete on mount (cache hit)', () => {
        // jsdom images default `complete` to false, matching a real browser
        // mid-fetch — this stubs the cache-hit case, where `complete` is
        // already true before React ever attaches the `onLoad` listener.
        vi.spyOn(HTMLImageElement.prototype, 'complete', 'get').mockReturnValue(
            true,
        );

        const { getByAltText } = render(
            <GifTile
                item={gifItem({})}
                isFavorite={false}
                onSelect={vi.fn()}
                onToggleFavorite={vi.fn()}
            />,
        );

        const image = getByAltText('junk');

        expect(image.className).not.toContain('opacity-0');
    });

    it('still reveals a non-cached image once it fires onLoad', () => {
        const { getByAltText } = render(
            <GifTile
                item={gifItem({})}
                isFavorite={false}
                onSelect={vi.fn()}
                onToggleFavorite={vi.fn()}
            />,
        );

        const image = getByAltText('junk');
        expect(image.className).toContain('opacity-0');

        fireEvent.load(image);

        expect(image.className).not.toContain('opacity-0');
    });
});

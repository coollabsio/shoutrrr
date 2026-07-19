/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type { MediaView, PostFormat } from '@/types/compose';

import { InstagramPreview } from '../instagram-preview';
import { imageMedia, makePreview, videoMedia } from './fixtures';

let root: Root | null = null;
let container: HTMLDivElement | null = null;

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    globalThis.PointerEvent = MouseEvent as unknown as typeof PointerEvent;
});

function swipe(el: HTMLElement, fromX: number, toX: number): void {
    act(() => {
        el.dispatchEvent(
            new MouseEvent('pointerdown', { clientX: fromX, bubbles: true }),
        );
        el.dispatchEvent(
            new MouseEvent('pointerup', { clientX: toX, bubbles: true }),
        );
    });
}

afterEach(() => {
    if (root) {
        act(() => root?.unmount());
    }
    container?.remove();
    root = null;
    container = null;
});

function mount(preview: PlatformPreview): HTMLDivElement {
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => {
        root?.render(createElement(InstagramPreview, { preview }));
    });

    return container;
}

function renderFeed(count: number): HTMLDivElement {
    const media = Array.from({ length: count }, (_, i) => imageMedia(`m${i}`));

    return mount(makePreview('instagram', media));
}

function renderFormat(
    media: MediaView[],
    format: PostFormat,
    caption?: string,
): HTMLDivElement {
    return mount(makePreview('instagram', media, caption, format));
}

describe('InstagramPreview feed', () => {
    it('shows the carousel counter and steps through slides', () => {
        const el = renderFeed(3);

        expect(el.textContent).toContain('1/3');

        const next = el.querySelector<HTMLButtonElement>(
            '[aria-label="Next photo"]',
        );
        act(() => next?.click());
        expect(el.textContent).toContain('2/3');
    });

    it('advances and rewinds the carousel with a swipe', () => {
        const el = renderFeed(3);
        const carousel = el.querySelector<HTMLElement>('.aspect-square');
        expect(carousel).not.toBeNull();

        swipe(carousel!, 220, 60);
        expect(el.textContent).toContain('2/3');

        swipe(carousel!, 60, 220);
        expect(el.textContent).toContain('1/3');
    });

    it('ignores a tap that does not cross the swipe threshold', () => {
        const el = renderFeed(3);
        const carousel = el.querySelector<HTMLElement>('.aspect-square');

        swipe(carousel!, 120, 110);
        expect(el.textContent).toContain('1/3');
    });

    it('jumps straight to a slide when its dot is clicked', () => {
        const el = renderFeed(3);
        const thirdDot = el.querySelector<HTMLButtonElement>(
            '[aria-label="Go to photo 3"]',
        );
        act(() => thirdDot?.click());
        expect(el.textContent).toContain('3/3');
    });

    it('renders no carousel chrome for a single photo', () => {
        const el = renderFeed(1);

        expect(el.querySelector('[aria-label="Next photo"]')).toBeNull();
        expect(el.textContent).not.toContain('1/1');
    });

    it('prompts for media when the post has none', () => {
        const el = renderFeed(0);

        expect(el.textContent).toContain(
            'Instagram posts always include media',
        );
    });

    it('links hashtags in the caption', () => {
        const el = renderFormat(
            [imageMedia('m0')],
            'feed',
            'Golden hour #harbor',
        );

        const link = el.querySelector<HTMLAnchorElement>(
            'a[href*="explore/tags/harbor"]',
        );
        expect(link?.textContent).toBe('#harbor');
    });
});

describe('InstagramPreview story', () => {
    it('shows only the first attachment with no carousel or caption', () => {
        const el = renderFormat(
            [imageMedia('m0'), videoMedia('m1'), imageMedia('m2')],
            'story',
            'Golden hour #harbor',
        );

        // A Story publishes just the first media item — no carousel, no
        // second segment, and no caption.
        expect(el.querySelector('img[src*="m0"]')).not.toBeNull();
        expect(el.querySelector('img[src*="m2"]')).toBeNull();
        expect(el.querySelector('video')).toBeNull();
        expect(el.querySelector('[aria-label="Next story"]')).toBeNull();
        expect(el.querySelector('[aria-label="Next photo"]')).toBeNull();
        expect(el.textContent).not.toContain('#harbor');
        expect(el.textContent).not.toContain('View all comments');
    });

    it('plays a video story when the first attachment is a video', () => {
        const el = renderFormat([videoMedia('v0')], 'story');

        expect(el.querySelector('video')).not.toBeNull();
    });

    it('prompts for media when the story has none', () => {
        const el = renderFormat([], 'story');

        expect(el.textContent).toContain('preview your story');
    });
});

describe('InstagramPreview reels', () => {
    it('plays the first video and keeps the caption', () => {
        const el = renderFormat(
            [imageMedia('m0'), videoMedia('v1')],
            'reels',
            'Golden hour #harbor',
        );

        expect(el.querySelector('video')).not.toBeNull();
        const link = el.querySelector<HTMLAnchorElement>(
            'a[href*="explore/tags/harbor"]',
        );
        expect(link?.textContent).toBe('#harbor');
    });

    it('prompts for a video when the reel has none', () => {
        const el = renderFormat([imageMedia('m0')], 'reels');

        expect(el.querySelector('video')).toBeNull();
        expect(el.textContent).toContain('Reels are single-video posts');
    });
});

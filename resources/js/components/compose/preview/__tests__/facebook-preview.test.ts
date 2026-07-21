/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type { MediaView, PostFormat } from '@/types/compose';

import { FacebookPreview } from '../facebook-preview';
import { imageMedia, makePreview, videoMedia } from './fixtures';

let root: Root | null = null;
let container: HTMLDivElement | null = null;

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

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
        root?.render(createElement(FacebookPreview, { preview }));
    });

    return container;
}

function renderFeed(count: number, caption?: string): HTMLDivElement {
    const media = Array.from({ length: count }, (_, i) => imageMedia(`m${i}`));

    return mount(makePreview('facebook', media, caption));
}

function renderFormat(
    media: MediaView[],
    format: PostFormat,
    caption?: string,
): HTMLDivElement {
    return mount(makePreview('facebook', media, caption, format));
}

describe('FacebookPreview feed', () => {
    it('renders the caption above the media', () => {
        const el = renderFeed(1, 'Launch day is here');

        expect(el.textContent).toContain('Launch day is here');
    });

    it('caps the album mosaic at five tiles with a "+N" overlay', () => {
        const el = renderFeed(8);
        const tiles = el.querySelectorAll('img');

        expect(tiles).toHaveLength(5);
        expect(el.textContent).toContain('+3');
    });
});

describe('FacebookPreview story', () => {
    it('shows only the first attachment with no caption', () => {
        const el = renderFormat(
            [imageMedia('m0'), imageMedia('m1')],
            'story',
            'Launch day is here',
        );

        expect(el.querySelector('img[src*="m0"]')).not.toBeNull();
        expect(el.querySelector('img[src*="m1"]')).toBeNull();
        expect(el.textContent).not.toContain('Launch day is here');
    });
});

describe('FacebookPreview reels', () => {
    it('plays the first video and keeps the caption', () => {
        const el = renderFormat(
            [imageMedia('m0'), videoMedia('v1')],
            'reels',
            'Launch day is here',
        );

        expect(el.querySelector('video')).not.toBeNull();
        expect(el.textContent).toContain('Launch day is here');
    });

    it('prompts for a video when the reel has none', () => {
        const el = renderFormat([imageMedia('m0')], 'reels');

        expect(el.querySelector('video')).toBeNull();
        expect(el.textContent).toContain('Reels are single-video posts');
    });
});

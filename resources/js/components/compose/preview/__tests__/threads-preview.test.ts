/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import type {
    PlatformPreview,
    PlatformPreviewItem,
} from '@/lib/compose/platform-preview';
import type { MediaView } from '@/types/compose';

import { ThreadsPreview } from '../threads-preview';
import { imageMedia, makePreview } from './fixtures';

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
        root?.render(createElement(ThreadsPreview, { preview }));
    });

    return container;
}

function post(
    id: string,
    text: string,
    media: MediaView[] = [],
): PlatformPreviewItem {
    return {
        id,
        text,
        media,
        count: text.length,
        overLimit: false,
        linkExclusions: [],
    };
}

function thread(items: PlatformPreviewItem[]): PlatformPreview {
    return { ...makePreview('threads', []), items };
}

describe('ThreadsPreview', () => {
    it('renders a single post with Threads chrome', () => {
        const el = mount(
            makePreview('threads', [imageMedia('m0')], 'Launch day is here'),
        );

        expect(el.querySelectorAll('article')).toHaveLength(1);
        expect(el.textContent).toContain('Launch day is here');
        expect(el.querySelector('[aria-label="Verified"]')).not.toBeNull();
        expect(el.textContent).toContain('Like · Comment · Repost · Share');
        expect(el.textContent).toContain('Posted as one Threads post');
    });

    it('chains non-empty sections into a thread', () => {
        const el = mount(
            thread([
                post('t1', 'First', [imageMedia('m0')]),
                post('t2', 'Second'),
                post('t3', 'Third'),
            ]),
        );

        expect(el.querySelectorAll('article')).toHaveLength(3);
        expect(el.textContent).toContain('Chained into a 3-post thread');
    });

    it('drops empty sections but keeps the media-only first post', () => {
        const el = mount(
            thread([post('t1', '', [imageMedia('m0')]), post('t2', '')]),
        );

        expect(el.querySelectorAll('article')).toHaveLength(1);
        expect(el.querySelector('img[src*="m0"]')).not.toBeNull();
    });

    it('renders a multi-image post as a carousel of tiles', () => {
        const el = mount(
            makePreview('threads', [imageMedia('m0'), imageMedia('m1')]),
        );

        expect(el.querySelectorAll('img')).toHaveLength(2);
    });

    it('links hashtags in the post text', () => {
        const el = mount(
            makePreview('threads', [imageMedia('m0')], 'Golden hour #harbor'),
        );

        const link = el.querySelector<HTMLAnchorElement>(
            'a[href*="search?q=harbor"]',
        );
        expect(link?.textContent).toBe('#harbor');
    });

    it('prompts when the draft is empty', () => {
        const el = mount(makePreview('threads', [], ''));

        expect(el.textContent).toContain(
            'Start writing to preview your Threads post',
        );
    });
});

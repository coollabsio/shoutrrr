/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type { TikTokPostMode } from '@/types/compose';

import { TikTokPreview } from '../tiktok-preview';
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

function currentPhoto(el: HTMLElement): string | null {
    return (
        el.querySelector<HTMLImageElement>('[data-slot="tiktok-photos"] img')
            ?.src ?? null
    );
}

afterEach(() => {
    if (root) {
        act(() => root?.unmount());
    }
    container?.remove();
    root = null;
    container = null;
});

function mount(
    preview: PlatformPreview,
    postMode: TikTokPostMode = 'direct',
): HTMLDivElement {
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => {
        root?.render(createElement(TikTokPreview, { preview, postMode }));
    });

    return container;
}

describe('TikTokPreview', () => {
    it('shows a video with the caption and username for a Direct Post', () => {
        const el = mount(makePreview('tiktok', [videoMedia('v1')], 'New drop'));

        expect(el.querySelector('video')).not.toBeNull();
        expect(el.textContent).toContain('harbor.studio');
        expect(el.textContent).not.toContain('@harbor.studio');
        expect(el.textContent).toContain('New drop');
        expect(el.textContent).toContain('Posted to the TikTok profile');
    });

    it('opens a photo post on its cover and swipes through the rest', () => {
        const el = mount(
            makePreview('tiktok', [
                imageMedia('p1'),
                imageMedia('p2'),
                imageMedia('p3'),
            ]),
        );
        const slides = el.querySelector<HTMLElement>(
            '[data-slot="tiktok-photos"]',
        );

        expect(currentPhoto(el)).toMatch(/p1\.jpg$/);
        expect(el.querySelectorAll('[aria-label^="Go to photo"]')).toHaveLength(
            3,
        );

        swipe(slides!, 220, 60);
        expect(currentPhoto(el)).toMatch(/p2\.jpg$/);

        swipe(slides!, 60, 220);
        expect(currentPhoto(el)).toMatch(/p1\.jpg$/);

        swipe(slides!, 60, 220);
        expect(currentPhoto(el)).toMatch(/p1\.jpg$/);
    });

    it('jumps to a photo when its dot is clicked', () => {
        const el = mount(
            makePreview('tiktok', [
                imageMedia('p1'),
                imageMedia('p2'),
                imageMedia('p3'),
            ]),
        );
        const third = el.querySelector<HTMLButtonElement>(
            '[aria-label="Go to photo 3"]',
        );

        act(() => third?.click());

        expect(currentPhoto(el)).toMatch(/p3\.jpg$/);
        expect(third?.getAttribute('aria-current')).toBe('true');
    });

    it('shows no photo dots for a single photo', () => {
        const el = mount(makePreview('tiktok', [imageMedia('p1')]));

        expect(currentPhoto(el)).toMatch(/p1\.jpg$/);
        expect(el.querySelector('[aria-label^="Go to photo"]')).toBeNull();
    });

    it("draws TikTok's own feed chrome around the post", () => {
        const el = mount(makePreview('tiktok', [videoMedia('v1')]));

        expect(el.textContent).toContain('Following');
        expect(el.textContent).toContain('For You');
        expect(el.textContent).toContain('original sound - harbor.studio');
        expect(el.textContent).toContain('Share');
        expect(
            el.querySelector('[data-slot="tiktok-sound-disc"]'),
        ).not.toBeNull();
    });

    it('links hashtags and mentions to TikTok in bold white', () => {
        const el = mount(
            makePreview(
                'tiktok',
                [imageMedia('p1')],
                'Spring drop with @harbor.crew #SpringDrop at shoutrrr.com',
            ),
        );

        const tag = el.querySelector<HTMLAnchorElement>(
            'a[href="https://www.tiktok.com/tag/springdrop"]',
        );
        const mention = el.querySelector<HTMLAnchorElement>(
            'a[href="https://www.tiktok.com/@harbor.crew"]',
        );
        expect(tag?.textContent).toBe('#SpringDrop');
        expect(tag?.className).toContain('text-white');
        expect(mention?.textContent).toBe('@harbor.crew');
        expect(el.querySelector('a[href*="shoutrrr.com"]')).toBeNull();
    });

    it('leaves the caption off a video sent to the inbox', () => {
        const el = mount(
            makePreview('tiktok', [videoMedia('v1')], 'Not sent'),
            'inbox',
        );

        expect(el.textContent).not.toContain('Not sent');
        expect(el.textContent).toContain(
            'the caption is added in the TikTok app',
        );
    });

    it('keeps the caption on photos sent to the inbox', () => {
        const el = mount(
            makePreview('tiktok', [imageMedia('p1')], 'Photo caption'),
            'inbox',
        );

        expect(el.textContent).toContain('Photo caption');
        expect(el.textContent).toContain("Sent to the creator's TikTok inbox");
    });

    it('asks for media when there is none', () => {
        const el = mount(makePreview('tiktok', [], 'Text only'));

        expect(el.textContent).toContain('TikTok posts need media');
    });
});

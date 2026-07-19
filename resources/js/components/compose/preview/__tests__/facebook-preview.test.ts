/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import { FacebookPreview } from '../facebook-preview';
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

function render(count: number, caption?: string): HTMLDivElement {
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    const media = Array.from({ length: count }, (_, i) => imageMedia(`m${i}`));
    act(() => {
        root?.render(
            createElement(FacebookPreview, {
                preview: makePreview('facebook', media, caption),
            }),
        );
    });

    return container;
}

function findByText(el: HTMLElement, text: string): HTMLButtonElement {
    const button = [...el.querySelectorAll('button')].find(
        (node) => node.textContent?.trim() === text,
    );
    if (!button) {
        throw new Error(`No button with text "${text}"`);
    }

    return button as HTMLButtonElement;
}

describe('FacebookPreview', () => {
    it('renders the caption above the media', () => {
        const el = render(1, 'Launch day is here');

        expect(el.textContent).toContain('Launch day is here');
    });

    it('caps the album mosaic at five tiles with a "+N" overlay', () => {
        const el = render(8);
        const tiles = el.querySelectorAll('img');

        expect(tiles).toHaveLength(5);
        expect(el.textContent).toContain('+3');
    });

    it('switches between the feed post and the 9:16 story', () => {
        const el = render(2);

        expect(el.textContent).not.toContain('9:16');

        act(() => findByText(el, 'Story').click());
        expect(el.textContent).toContain('9:16');

        act(() => findByText(el, 'Feed').click());
        expect(el.textContent).not.toContain('9:16');
    });
});

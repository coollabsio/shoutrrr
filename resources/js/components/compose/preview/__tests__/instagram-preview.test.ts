/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import { InstagramPreview } from '../instagram-preview';
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

function render(count: number): HTMLDivElement {
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    const media = Array.from({ length: count }, (_, i) => imageMedia(`m${i}`));
    act(() => {
        root?.render(
            createElement(InstagramPreview, {
                preview: makePreview('instagram', media),
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

describe('InstagramPreview', () => {
    it('shows the carousel counter and steps through slides', () => {
        const el = render(3);

        expect(el.textContent).toContain('1/3');

        const next = el.querySelector<HTMLButtonElement>(
            '[aria-label="Next photo"]',
        );
        act(() => next?.click());
        expect(el.textContent).toContain('2/3');
    });

    it('renders no carousel chrome for a single photo', () => {
        const el = render(1);

        expect(el.querySelector('[aria-label="Next photo"]')).toBeNull();
        expect(el.textContent).not.toContain('1/1');
    });

    it('switches between the feed post and the 9:16 story', () => {
        const el = render(3);

        expect(el.textContent).toContain('View all comments');
        expect(el.textContent).not.toContain('9:16');

        act(() => findByText(el, 'Story').click());

        expect(el.textContent).toContain('9:16');
        expect(el.querySelector('[aria-label="Next photo"]')).toBeNull();

        act(() => findByText(el, 'Post').click());
        expect(el.textContent).toContain('View all comments');
    });

    it('prompts for media when the post has none', () => {
        const el = render(0);

        expect(el.textContent).toContain('Instagram posts always include media');
    });
});

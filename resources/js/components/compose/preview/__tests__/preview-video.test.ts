/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import { PreviewVideo } from '../preview-video';

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

function render(): HTMLDivElement {
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => {
        root?.render(
            createElement(PreviewVideo, {
                src: 'https://cdn.example.test/clip.mp4',
            }),
        );
    });

    return container;
}

describe('PreviewVideo', () => {
    it('autoplays muted, looping, and inline by default', () => {
        const el = render();
        const video = el.querySelector('video');

        expect(video).not.toBeNull();
        expect(video?.muted).toBe(true);
        expect(video?.hasAttribute('autoplay')).toBe(true);
        expect(video?.hasAttribute('loop')).toBe(true);
    });

    it('toggles sound on and off from the corner button', () => {
        const el = render();
        const video = el.querySelector('video');
        const button = el.querySelector('button');

        expect(button?.getAttribute('aria-label')).toBe('Unmute video');
        expect(button?.getAttribute('aria-pressed')).toBe('false');

        act(() => button?.click());

        expect(video?.muted).toBe(false);
        expect(button?.getAttribute('aria-label')).toBe('Mute video');
        expect(button?.getAttribute('aria-pressed')).toBe('true');

        act(() => button?.click());

        expect(video?.muted).toBe(true);
        expect(button?.getAttribute('aria-label')).toBe('Unmute video');
    });
});

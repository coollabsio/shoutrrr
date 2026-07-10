/** @vitest-environment jsdom */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import {
    type ChipTarget,
    TargetStatusChips,
} from '@/components/compose/target-status-chips';
import { TooltipProvider } from '@/components/ui/tooltip';

const failedTarget = (overrides: Partial<ChipTarget> = {}): ChipTarget => ({
    id: 't1',
    platform: 'bluesky',
    status: 'failed',
    error_message: 'Remote server rejected the post',
    attempts: 2,
    ...overrides,
});

let mountedRoot: Root | null = null;
let mountedContainer: HTMLDivElement | null = null;

beforeAll(() => {
    globalThis.ResizeObserver = class ResizeObserver {
        observe() {}
        unobserve() {}
        disconnect() {}
    };

    globalThis.PointerEvent = MouseEvent as typeof PointerEvent;
});

afterEach(() => {
    if (mountedRoot) {
        act(() => mountedRoot?.unmount());
    }

    mountedContainer?.remove();
    mountedRoot = null;
    mountedContainer = null;
});

const renderChips = (targets: ChipTarget[]): HTMLDivElement => {
    const container = document.createElement('div');
    document.body.append(container);

    mountedContainer = container;
    mountedRoot = createRoot(container);

    act(() => {
        mountedRoot?.render(
            createElement(
                TooltipProvider,
                null,
                createElement(TargetStatusChips, { targets }),
            ),
        );
    });

    return container;
};

describe('target status chips', () => {
    // The failure copy shown in the tooltip is also rendered into the tooltip's
    // trigger button (always in the DOM), so the attempt-prefix formatting is
    // asserted against the trigger. Base UI's tooltip popup only mounts once
    // floating-ui registers a real hover/focus interaction, which jsdom does not
    // provide (see the readable-styling test for how the popup styling is
    // covered); the open-on-hover/focus behavior itself is verified in-browser.
    it('renders the attempt-prefixed failure message in a focusable trigger', () => {
        const container = renderChips([failedTarget()]);
        const trigger = container.querySelector('button');

        expect(trigger?.textContent).toBe(
            'Attempt 2: Remote server rejected the post',
        );

        act(() => trigger?.focus());

        expect(document.activeElement).toBe(trigger);
        expect(document.activeElement?.tagName).toBe('BUTTON');
    });

    it('styles the failure tooltip as readable, wrapped text', () => {
        // The popup element is not mountable under jsdom (see note above), so its
        // readable-text styling is pinned at the source level instead.
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/target-status-chips.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('whitespace-normal');
        expect(source).toContain('[--tooltip-bg:var(--popover)]');
    });

    it('omits the attempt prefix when there were no recorded attempts', () => {
        const container = renderChips([
            failedTarget({ attempts: 0, error_message: 'Network timeout' }),
        ]);
        const trigger = container.querySelector('button');

        expect(trigger?.textContent).toBe('Network timeout');
        expect(trigger?.textContent).not.toContain('Attempt');
    });

    it('renders no failure tooltip trigger for non-failed targets', () => {
        const container = renderChips([
            failedTarget({ status: 'published', error_message: null }),
        ]);

        expect(container.querySelector('button')).toBeNull();
        expect(document.querySelector('[role="tooltip"]')).toBeNull();
    });
});

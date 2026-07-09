/** @vitest-environment jsdom */

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

const waitForElement = async (selector: string): Promise<Element> => {
    for (let attempt = 0; attempt < 10; attempt += 1) {
        const element = document.querySelector(selector);

        if (element) {
            return element;
        }

        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    throw new Error(`Could not find ${selector}`);
};

const tooltipContent = (): Element | null =>
    document.querySelector('[data-slot="tooltip-content"]');

describe('target status chips', () => {
    it('shows the failure message in a readable tooltip on focus', async () => {
        const container = renderChips([failedTarget()]);
        const trigger = container.querySelector('button');

        act(() => trigger?.focus());

        await waitForElement('[role="tooltip"]');

        expect(tooltipContent()?.textContent).toContain(
            'Attempt 2: Remote server rejected the post',
        );
        expect(tooltipContent()?.classList.contains('whitespace-normal')).toBe(
            true,
        );
        expect(document.activeElement?.textContent).toBe(
            'Attempt 2: Remote server rejected the post',
        );
        expect(document.activeElement?.tagName).toBe('BUTTON');
    });

    it('shows the failure message in a readable tooltip on hover', async () => {
        const container = renderChips([failedTarget()]);
        const trigger = container.querySelector('button');

        act(() => {
            trigger?.dispatchEvent(
                new PointerEvent('pointermove', {
                    bubbles: true,
                    pointerType: 'mouse',
                }),
            );
        });

        await waitForElement('[role="tooltip"]');

        expect(tooltipContent()?.textContent).toContain(
            'Attempt 2: Remote server rejected the post',
        );
    });

    it('omits the attempt prefix when there were no recorded attempts', async () => {
        const container = renderChips([
            failedTarget({ attempts: 0, error_message: 'Network timeout' }),
        ]);
        const trigger = container.querySelector('button');

        act(() => {
            trigger?.dispatchEvent(
                new PointerEvent('pointermove', {
                    bubbles: true,
                    pointerType: 'mouse',
                }),
            );
        });

        await waitForElement('[role="tooltip"]');

        expect(tooltipContent()?.textContent).toContain('Network timeout');
        expect(tooltipContent()?.textContent).not.toContain('Attempt');
    });

    it('renders no failure tooltip for non-failed targets', async () => {
        renderChips([
            failedTarget({ status: 'published', error_message: null }),
        ]);

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[role="tooltip"]')).toBeNull();
    });
});

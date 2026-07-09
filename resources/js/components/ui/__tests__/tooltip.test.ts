// @vitest-environment jsdom
import React from 'react';

import { createRoot } from 'react-dom/client';
import { beforeAll, describe, expect, it, vi } from 'vitest';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '../tooltip';

beforeAll(() => {
    class ResizeObserver {
        observe = vi.fn();
        unobserve = vi.fn();
        disconnect = vi.fn();
    }

    globalThis.ResizeObserver = ResizeObserver;
});

describe('tooltip', () => {
    it('uses a real Radix arrow filled from the tooltip background', async () => {
        const container = document.createElement('div');
        document.body.append(container);
        const root = createRoot(container);

        root.render(
            React.createElement(
                TooltipProvider,
                null,
                React.createElement(
                    Tooltip,
                    { open: true },
                    React.createElement(
                        TooltipTrigger,
                        { asChild: true },
                        React.createElement('button', null, 'Trigger'),
                    ),
                    React.createElement(
                        TooltipContent,
                        { forceMount: true },
                        'Tooltip body',
                    ),
                ),
            ),
        );

        await vi.waitFor(() => {
            expect(
                document.querySelector('[data-slot="tooltip-content"]'),
            ).not.toBeNull();
        });

        const tooltip = document.querySelector('[data-slot="tooltip-content"]');
        const arrow = tooltip?.querySelector('svg');

        expect(
            tooltip?.classList.contains('[--tooltip-bg:var(--foreground)]'),
        ).toBe(true);
        expect(tooltip?.classList.contains('bg-(--tooltip-bg)')).toBe(true);
        expect(tooltip?.classList.contains('rotate-45')).toBe(false);
        expect(tooltip?.classList.contains('bg-foreground')).toBe(false);
        expect(tooltip?.classList.contains('fill-foreground')).toBe(false);

        expect(arrow).not.toBeNull();
        expect(arrow?.classList.contains('fill-(--tooltip-bg)')).toBe(true);
        expect(arrow?.classList.contains('rotate-45')).toBe(false);

        root.unmount();
        container.remove();
    });
});

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
    it('drives its surface and arrow from the --tooltip-bg variable', async () => {
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
                        { render: React.createElement('button', null, 'Trigger') },
                    ),
                    React.createElement(TooltipContent, null, 'Tooltip body'),
                ),
            ),
        );

        await vi.waitFor(() => {
            expect(
                document.querySelector('[data-slot="tooltip-content"]'),
            ).not.toBeNull();
        });

        const tooltip = document.querySelector('[data-slot="tooltip-content"]');
        // Base UI renders the arrow as a plain <div> (last child of the popup),
        // not the old Radix <svg>. Both surface and arrow must resolve their
        // color through --tooltip-bg so consumers can recolor the tooltip
        // (e.g. target-status-chips overrides it to var(--popover)).
        const arrow = tooltip?.lastElementChild;

        expect(
            tooltip?.classList.contains('[--tooltip-bg:var(--foreground)]'),
        ).toBe(true);
        expect(tooltip?.classList.contains('bg-(--tooltip-bg)')).toBe(true);
        expect(tooltip?.classList.contains('bg-foreground')).toBe(false);

        expect(arrow).not.toBeNull();
        expect(arrow?.classList.contains('bg-(--tooltip-bg)')).toBe(true);
        expect(arrow?.classList.contains('fill-(--tooltip-bg)')).toBe(true);
        expect(arrow?.classList.contains('bg-foreground')).toBe(false);
        expect(arrow?.classList.contains('fill-foreground')).toBe(false);

        root.unmount();
        container.remove();
    });
});

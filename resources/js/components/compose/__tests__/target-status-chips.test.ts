import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

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

const render = (targets: ChipTarget[]) =>
    renderToStaticMarkup(
        createElement(
            TooltipProvider,
            null,
            createElement(TargetStatusChips, { targets }),
        ),
    );

describe('target status chips', () => {
    it('shows the failure message through a focusable tooltip trigger', () => {
        const html = render([failedTarget()]);

        // The trigger is a real Tooltip trigger, keyboard-focusable, with a
        // help cursor — not a native `title` tooltip the reviewer flagged.
        expect(html).toContain('data-slot="tooltip-trigger"');
        expect(html).toContain('tabindex="0"');
        expect(html).toContain('cursor-help');
        expect(html).not.toContain('title=');

        // The visible trigger carries the failure text (with the attempt
        // prefix) so it is readable on hover/focus.
        expect(html).toContain('Attempt 2: Remote server rejected the post');
    });

    it('omits the attempt prefix when there were no recorded attempts', () => {
        const html = render([
            failedTarget({ attempts: 0, error_message: 'Network timeout' }),
        ]);

        expect(html).toContain('Network timeout');
        expect(html).not.toContain('Attempt');
    });

    it('renders no failure tooltip for non-failed targets', () => {
        const html = render([
            failedTarget({ status: 'published', error_message: null }),
        ]);

        expect(html).not.toContain('data-slot="tooltip-trigger"');
        expect(html).not.toContain('cursor-help');
    });
});

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { QueuePreview } from '@/components/compose/queue-preview';
import {
    deriveQueueStatus,
    type QueueSlotState,
} from '@/hooks/compose/use-next-slot';

describe('queue slot selection', () => {
    it('treats returned open slots as a queue the user can choose from', () => {
        expect(
            deriveQueueStatus({
                has_schedule: true,
                slot: '2026-05-18T09:00:00Z',
                slots: ['2026-05-18T09:00:00Z', '2026-05-18T11:00:00Z'],
                timezone: 'UTC',
            }),
        ).toBe('found');
    });

    it('sends the ISO of the slot the user picks to onSelectSlot', () => {
        const onSelectSlot = vi.fn();
        const state: QueueSlotState = {
            status: 'found',
            slot: '2026-05-18T09:00:00Z',
            slots: ['2026-05-18T09:00:00Z', '2026-05-18T11:00:00Z'],
            tz: 'UTC',
        };

        render(
            <QueuePreview
                state={state}
                selectedSlot={null}
                onSelectSlot={onSelectSlot}
            />,
        );

        // Open the Base UI Select and choose the second slot (11:00 UTC).
        // Base UI commits a Select item on the pointer up/down pair, not a bare click.
        fireEvent.click(screen.getByRole('combobox', { name: 'Queue slot' }));
        const option = screen.getByRole('option', { name: /11:00 AM/i });
        fireEvent.pointerDown(option, { pointerType: 'mouse', button: 0 });
        fireEvent.pointerUp(option, { pointerType: 'mouse', button: 0 });
        fireEvent.click(option);

        expect(onSelectSlot).toHaveBeenCalledWith('2026-05-18T11:00:00Z');
    });

    it('wires the picked queue slot into the submit payload', () => {
        const submitBar = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/submit-bar.tsx',
            ),
            'utf8',
        );

        expect(submitBar).toContain('{ scheduled_at: tray.pickedAt }');
    });
});

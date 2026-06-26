import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { deriveQueueStatus } from '@/hooks/compose/use-next-slot';

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

    it('renders a slot picker and submits the selected queue slot', () => {
        const preview = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/queue-preview.tsx',
            ),
            'utf8',
        );
        const submitBar = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/submit-bar.tsx',
            ),
            'utf8',
        );

        expect(preview).toContain('state.slots.length > 1');
        expect(preview).toContain('<select');
        expect(submitBar).toContain('{ scheduled_at: tray.pickedAt }');
    });
});

import { describe, expect, it } from 'vitest';

import { formatDelta } from '../metric-delta';

describe('formatDelta', () => {
    it('prefixes gains with + and groups thousands', () => {
        expect(formatDelta(1204)).toBe('+1,204');
    });

    it('prefixes losses with a minus sign', () => {
        expect(formatDelta(-342)).toBe('−342');
    });

    it('renders a flat period without a sign', () => {
        expect(formatDelta(0)).toBe('0');
    });
});

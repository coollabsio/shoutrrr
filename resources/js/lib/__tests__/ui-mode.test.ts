import { describe, expect, it } from 'vitest';

import { resolveUiMode } from '@/lib/ui-mode';

describe('resolveUiMode', () => {
    it('defaults missing and unknown values to agentic', () => {
        expect(resolveUiMode(undefined)).toBe('agentic');
        expect(resolveUiMode(null)).toBe('agentic');
        expect(resolveUiMode('')).toBe('agentic');
        expect(resolveUiMode('agentic')).toBe('agentic');
    });

    it('keeps an explicit classic preference', () => {
        expect(resolveUiMode('classic')).toBe('classic');
    });
});

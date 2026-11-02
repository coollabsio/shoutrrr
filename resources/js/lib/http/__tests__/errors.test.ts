import { describe, expect, it } from 'vitest';

import { requireResponse } from '../errors';

describe('requireResponse', () => {
    it('returns a response body', () => {
        const body = { mention: { id: '1' } };

        expect(requireResponse(body)).toBe(body);
    });

    it('throws on the empty result of a 422', () => {
        expect(() => requireResponse(undefined)).toThrow();
    });
});

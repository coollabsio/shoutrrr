import { describe, expect, it } from 'vitest';

import { settle } from '../errors';

describe('settle', () => {
    it('reports the value of a request that worked', async () => {
        const body = { mention: { id: '1' } };

        expect(await settle(Promise.resolve(body))).toEqual({
            ok: true,
            value: body,
        });
    });

    it('treats an empty 204 body as success', async () => {
        expect(await settle(Promise.resolve(null))).toEqual({
            ok: true,
            value: null,
        });
    });

    it('reports a 422, which resolves with nothing', async () => {
        expect(await settle(Promise.resolve(undefined))).toEqual({ ok: false });
    });

    it('reports a failed request instead of throwing', async () => {
        expect(await settle(Promise.reject(new Error('502')))).toEqual({
            ok: false,
        });
    });
});

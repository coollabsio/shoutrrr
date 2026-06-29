import { describe, expect, it } from 'vitest';

import { parseSseChunk } from '../sse';

describe('parseSseChunk', () => {
    it('parses complete frames and keeps the remainder', () => {
        const raw =
            'data: {"type":"delta","text":"Hi"}\n\n' +
            'data: {"type":"delta","text":" there"}\n\n' +
            'data: {"type":"do';
        const { events, rest } = parseSseChunk(raw);

        expect(events).toEqual([
            { type: 'delta', text: 'Hi' },
            { type: 'delta', text: ' there' },
        ]);
        expect(rest).toBe('data: {"type":"do');
    });

    it('ignores blank/non-data lines', () => {
        const { events } = parseSseChunk(
            ': comment\n\ndata: {"type":"done"}\n\n',
        );
        expect(events).toEqual([{ type: 'done' }]);
    });
});

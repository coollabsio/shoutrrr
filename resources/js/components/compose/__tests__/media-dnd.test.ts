import { describe, expect, it, vi } from 'vitest';

import { getMediaDrag, setMediaDrag } from '@/lib/compose/media-dnd';

function fakeEvent() {
    const store = new Map<string, string>();
    const dataTransfer = {
        setData: vi.fn((format: string, data: string) => {
            store.set(format, data);
        }),
        getData: vi.fn((format: string) => store.get(format) ?? ''),
    };

    return { dataTransfer };
}

describe('media-dnd', () => {
    it('round-trips a media id through setMediaDrag/getMediaDrag', () => {
        const e = fakeEvent();
        setMediaDrag(e, 'm1');

        expect(e.dataTransfer.setData).toHaveBeenCalledWith(
            'application/x-composer-media',
            'm1',
        );
        expect(getMediaDrag(e)).toBe('m1');
    });

    it('returns null when the namespaced key was never set', () => {
        const e = fakeEvent();

        expect(getMediaDrag(e)).toBeNull();
    });

    it('is inert against a plain dataTransfer carrying an unrelated payload', () => {
        const e = fakeEvent();
        e.dataTransfer.setData('text/uri-list', 'https://example.test');

        expect(getMediaDrag(e)).toBeNull();
    });
});

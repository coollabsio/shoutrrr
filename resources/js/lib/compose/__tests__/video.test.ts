import { describe, expect, it } from 'vitest';

import { validateVideo } from '@/lib/compose/video';
import type { PlatformLimits } from '@/types/compose';

function limits(
    over: Partial<PlatformLimits> & Pick<PlatformLimits, 'platform'>,
): PlatformLimits {
    return {
        maxLength: 0,
        maxBytes: null,
        maxMedia: 1,
        maxMediaBytes: 0,
        allowedMime: [],
        threadMax: null,
        maxImageDimensions: { width: 0, height: 0 },
        allowedVideoMime: ['video/mp4'],
        maxVideoBytes: 100_000_000,
        maxVideoDurationSeconds: 180,
        ...over,
    };
}

describe('validateVideo', () => {
    const meta = {
        sizeBytes: 1_000,
        mime: 'video/mp4',
        durationSeconds: 30,
        width: 1280,
        height: 720,
    };

    it('accepts an in-spec video against the strictest selected platform', () => {
        const result = validateVideo(meta, [
            limits({ platform: 'x' }),
            limits({ platform: 'bluesky' }),
        ]);
        expect(result.ok).toBe(true);
    });

    it('rejects a non-mp4 mime', () => {
        const result = validateVideo({ ...meta, mime: 'video/quicktime' }, [
            limits({ platform: 'x' }),
        ]);
        expect(result).toEqual({
            ok: false,
            reason: expect.stringContaining('MP4'),
        });
    });

    it('rejects when duration exceeds the minimum across platforms', () => {
        const result = validateVideo({ ...meta, durationSeconds: 200 }, [
            limits({ platform: 'x', maxVideoDurationSeconds: 140 }),
            limits({ platform: 'bluesky', maxVideoDurationSeconds: 180 }),
        ]);
        expect(result).toEqual({
            ok: false,
            reason: expect.stringContaining('140'),
        });
    });

    it('rejects when size exceeds the minimum across platforms', () => {
        const result = validateVideo({ ...meta, sizeBytes: 150_000_000 }, [
            limits({ platform: 'bluesky', maxVideoBytes: 100_000_000 }),
        ]);
        expect(result.ok).toBe(false);
    });
});

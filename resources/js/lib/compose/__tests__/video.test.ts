import { describe, expect, it } from 'vitest';

import {
    minVideoBytes,
    nextDownscale,
    planVideoEncode,
    validateVideo,
    videoLimitsForTargets,
    type VideoMeta,
} from '@/lib/compose/video';
import type { PlatformLimits } from '@/types/compose';

function limits(
    over: Partial<PlatformLimits> & Pick<PlatformLimits, 'platform'>,
): PlatformLimits {
    return {
        maxLength: 0,
        maxBytes: null,
        maxMedia: 1,
        requiresMedia: false,
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

describe('videoLimitsForTargets', () => {
    const platformLimits = [
        limits({ platform: 'x', maxVideoDurationSeconds: 140 }),
        limits({ platform: 'bluesky', maxVideoDurationSeconds: 180 }),
    ];

    it('uses the detected Premium X duration for a Premium-only target', () => {
        expect(
            videoLimitsForTargets(platformLimits, [
                { platform: 'x', max_video_duration_seconds: 14_400 },
            ]),
        ).toMatchObject([{ platform: 'x', maxVideoDurationSeconds: 14_400 }]);
    });

    it('keeps the free X duration when a free and Premium account are selected together', () => {
        expect(
            videoLimitsForTargets(platformLimits, [
                { platform: 'x', max_video_duration_seconds: 14_400 },
                { platform: 'x', max_video_duration_seconds: 140 },
            ]),
        ).toMatchObject([{ platform: 'x', maxVideoDurationSeconds: 140 }]);
    });
});

describe('minVideoBytes', () => {
    it('returns Infinity when no platform is selected', () => {
        expect(minVideoBytes([])).toBe(Number.POSITIVE_INFINITY);
    });

    it('returns the smallest cap across the selected platforms', () => {
        expect(
            minVideoBytes([
                limits({ platform: 'x', maxVideoBytes: 512_000_000 }),
                limits({ platform: 'bluesky', maxVideoBytes: 100_000_000 }),
            ]),
        ).toBe(100_000_000);
    });
});

describe('planVideoEncode', () => {
    const source: VideoMeta = {
        sizeBytes: 0,
        mime: 'video/mp4',
        durationSeconds: 100,
        width: 1280,
        height: 720,
    };

    it('derives the video bitrate from the byte budget minus the audio cap', () => {
        const plan = planVideoEncode(source, 100_000_000);
        expect(plan.audioBitrate).toBe(128_000);
        expect(plan.videoBitrate).toBe(
            Math.floor((100_000_000 * 8 * 0.9) / 100) - 128_000,
        );
        // Under the resolution ceiling: dimensions pass through unchanged.
        expect(plan).toMatchObject({ width: 1280, height: 720 });
    });

    it('caps the longest edge to the resolution ceiling, even-rounded', () => {
        const plan = planVideoEncode(
            { ...source, width: 3840, height: 2160 },
            100_000_000,
        );
        expect(plan.width).toBe(1920);
        expect(plan.height).toBe(1080);
        expect(plan.width % 2).toBe(0);
        expect(plan.height % 2).toBe(0);
    });

    it('never starves the video bitrate below the floor for a tiny cap', () => {
        const plan = planVideoEncode(
            { ...source, durationSeconds: 600, width: 640, height: 360 },
            1_000_000,
        );
        expect(plan.videoBitrate).toBe(300_000);
    });

    it('treats a zero duration as one second rather than dividing by zero', () => {
        const plan = planVideoEncode(
            { ...source, durationSeconds: 0 },
            100_000_000,
        );
        expect(Number.isFinite(plan.videoBitrate)).toBe(true);
        expect(plan.videoBitrate).toBeGreaterThan(0);
    });
});

describe('nextDownscale', () => {
    it('shrinks the longest edge ~20%, even-rounded', () => {
        expect(nextDownscale(1920, 1080)).toEqual({ width: 1536, height: 864 });
    });

    it('returns null once the longest edge would fall below the floor', () => {
        // 560 * 0.8 = 448, below the 480 floor.
        expect(nextDownscale(560, 320)).toBeNull();
    });
});

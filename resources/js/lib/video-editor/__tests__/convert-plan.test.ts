import { describe, expect, it } from 'vitest';

import type { PlatformLimits } from '@/types/compose';

import {
    convertErrorMessage,
    planConversion,
    type VideoProbe,
} from '../convert-plan';

function limits(
    overrides: Partial<
        Pick<PlatformLimits, 'maxVideoBytes' | 'maxVideoDurationSeconds'>
    > = {},
): PlatformLimits[] {
    return [
        {
            platform: 'x',
            maxLength: 280,
            maxBytes: null,
            maxMedia: 4,
            maxMediaBytes: 5_242_880,
            allowedMime: ['image/jpeg'],
            threadMax: null,
            maxImageDimensions: { width: 4096, height: 4096 },
            allowedVideoMime: ['video/mp4'],
            maxVideoBytes: 536_870_912,
            maxVideoDurationSeconds: 140,
            ...overrides,
        },
    ];
}

function probe(overrides: Partial<VideoProbe> = {}): VideoProbe {
    return {
        hasVideoTrack: true,
        canDecodeVideo: true,
        videoCodec: 'avc',
        audioCodec: 'aac',
        durationSeconds: 30,
        sizeBytes: 10_000_000,
        ...overrides,
    };
}

describe('planConversion', () => {
    it('rejects a file with no video track', () => {
        expect(
            planConversion(probe({ hasVideoTrack: false }), limits()),
        ).toEqual({ action: 'reject', reason: 'no-video-track' });
    });

    it('rejects a video the browser cannot decode', () => {
        expect(
            planConversion(probe({ canDecodeVideo: false }), limits()),
        ).toEqual({ action: 'reject', reason: 'cannot-decode' });
    });

    it('rejects a clip longer than the tightest platform limit', () => {
        expect(
            planConversion(probe({ durationSeconds: 200 }), limits()),
        ).toEqual({ action: 'reject', reason: 'too-long' });
    });

    it('remuxes H.264 + AAC within the byte cap', () => {
        expect(planConversion(probe(), limits())).toEqual({ action: 'remux' });
    });

    it('remuxes H.264 with no audio track', () => {
        expect(planConversion(probe({ audioCodec: null }), limits())).toEqual({
            action: 'remux',
        });
    });

    it('transcodes H.264 that is over the byte cap', () => {
        expect(
            planConversion(
                probe({ sizeBytes: 600_000_000 }),
                limits({ maxVideoBytes: 536_870_912 }),
            ),
        ).toEqual({ action: 'transcode' });
    });

    it('transcodes a non-H.264 codec (VP9)', () => {
        expect(planConversion(probe({ videoCodec: 'vp9' }), limits())).toEqual({
            action: 'transcode',
        });
    });

    it('transcodes H.264 with an incompatible audio codec (opus)', () => {
        expect(planConversion(probe({ audioCodec: 'opus' }), limits())).toEqual(
            { action: 'transcode' },
        );
    });
});

describe('convertErrorMessage', () => {
    it('names a missing video track', () => {
        expect(convertErrorMessage('no-video-track', limits())).toBe(
            "That file doesn't contain a video track.",
        );
    });

    it('reports the tightest duration limit for a too-long clip', () => {
        expect(
            convertErrorMessage(
                'too-long',
                limits({ maxVideoDurationSeconds: 140 }),
            ),
        ).toBe(
            'Video is too long; the limit is 140s for the selected platforms.',
        );
    });

    it('gives a generic hint when the browser cannot convert', () => {
        expect(convertErrorMessage('encode-unsupported', limits())).toBe(
            "Your browser couldn't convert that video. Try an MP4 (H.264) file.",
        );
        expect(convertErrorMessage('cannot-decode', limits())).toBe(
            "Your browser couldn't convert that video. Try an MP4 (H.264) file.",
        );
    });
});

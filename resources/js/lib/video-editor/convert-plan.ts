import { minVideoBytes } from '@/lib/compose/video';
import type { PlatformLimits } from '@/types/compose';

/** Why a file could not be converted. Maps 1:1 to a user-facing toast. */
export type ConvertReason =
    | 'no-video-track'
    | 'cannot-decode'
    | 'too-long'
    | 'encode-unsupported';

/** Thrown by `convertToMp4` when a file cannot be turned into a valid MP4. */
export class VideoConvertError extends Error {
    constructor(public readonly reason: ConvertReason) {
        super(reason);
        this.name = 'VideoConvertError';
    }
}

/**
 * The container/codec facts `planConversion` needs. Produced by probing the file
 * with mediabunny (see `convert.ts`); kept as a plain shape so the decision is
 * pure and unit-testable without a browser.
 */
export type VideoProbe = {
    hasVideoTrack: boolean;
    canDecodeVideo: boolean;
    videoCodec: string | null;
    audioCodec: string | null;
    durationSeconds: number;
    sizeBytes: number;
};

export type ConvertPlan =
    | { action: 'remux' }
    | { action: 'transcode' }
    | { action: 'reject'; reason: ConvertReason };

/** Tightest video duration cap across the selected platforms (∞ when none). */
function maxVideoDurationSeconds(limits: PlatformLimits[]): number {
    return Math.min(
        ...limits.map((l) => l.maxVideoDurationSeconds),
        Number.POSITIVE_INFINITY,
    );
}

/**
 * Decide how to turn a probed file into a platform-ready MP4: reject it, remux
 * (lossless container swap) when it is already H.264/AAC within the byte cap, or
 * re-encode otherwise.
 */
export function planConversion(
    probe: VideoProbe,
    limits: PlatformLimits[],
): ConvertPlan {
    if (!probe.hasVideoTrack) {
        return { action: 'reject', reason: 'no-video-track' };
    }
    if (!probe.canDecodeVideo) {
        return { action: 'reject', reason: 'cannot-decode' };
    }
    if (probe.durationSeconds > maxVideoDurationSeconds(limits)) {
        return { action: 'reject', reason: 'too-long' };
    }

    const audioCompatible =
        probe.audioCodec === null || probe.audioCodec === 'aac';
    if (
        probe.videoCodec === 'avc' &&
        audioCompatible &&
        probe.sizeBytes <= minVideoBytes(limits)
    ) {
        return { action: 'remux' };
    }

    return { action: 'transcode' };
}

/** User-facing toast copy for a conversion failure. */
export function convertErrorMessage(
    reason: ConvertReason,
    limits: PlatformLimits[],
): string {
    switch (reason) {
        case 'no-video-track':
            return "That file doesn't contain a video track.";
        case 'too-long':
            return `Video is too long; the limit is ${maxVideoDurationSeconds(limits)}s for the selected platforms.`;
        case 'cannot-decode':
        case 'encode-unsupported':
            return "Your browser couldn't convert that video. Try an MP4 (H.264) file.";
    }
}

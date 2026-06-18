import type { PlatformLimits } from '@/types/compose';

export type VideoMeta = {
    sizeBytes: number;
    mime: string;
    durationSeconds: number;
    width: number;
    height: number;
};

export function validateVideo(
    meta: VideoMeta,
    limits: PlatformLimits[],
): { ok: true } | { ok: false; reason: string } {
    if (!meta.mime.startsWith('video/')) {
        return { ok: false, reason: 'That file is not a video.' };
    }

    // No selected platform constraints: accept mp4 only (the common denominator).
    const allowed =
        limits.length > 0
            ? limits
                  .map((l) => l.allowedVideoMime)
                  .reduce(
                      (a, b) => a.filter((m) => b.includes(m)),
                      ['video/mp4'],
                  )
            : ['video/mp4'];

    if (!allowed.includes(meta.mime)) {
        return {
            ok: false,
            reason: 'Only MP4 (H.264/AAC) videos are supported.',
        };
    }

    const maxBytes = Math.min(
        ...limits.map((l) => l.maxVideoBytes),
        Number.POSITIVE_INFINITY,
    );
    if (meta.sizeBytes > maxBytes) {
        const mb = Math.floor(maxBytes / (1024 * 1024));
        return {
            ok: false,
            reason: `Video is too large; the limit is ${mb} MB for the selected platforms.`,
        };
    }

    const maxDuration = Math.min(
        ...limits.map((l) => l.maxVideoDurationSeconds),
        Number.POSITIVE_INFINITY,
    );
    if (meta.durationSeconds > maxDuration) {
        return {
            ok: false,
            reason: `Video is too long; the limit is ${maxDuration}s for the selected platforms.`,
        };
    }

    return { ok: true };
}

export function readVideoMetadata(file: File): Promise<VideoMeta> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.onloadedmetadata = () => {
            URL.revokeObjectURL(url);
            resolve({
                sizeBytes: file.size,
                mime: file.type,
                durationSeconds: Math.round(video.duration),
                width: video.videoWidth,
                height: video.videoHeight,
            });
        };
        video.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Could not read video metadata.'));
        };
        video.src = url;
    });
}

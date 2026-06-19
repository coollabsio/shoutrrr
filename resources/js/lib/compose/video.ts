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
                // Floor, not round: a 140.4s clip must not be rejected against a 140s cap.
                durationSeconds: Math.floor(video.duration),
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

/**
 * PUT a file directly to a presigned storage URL with the signed headers (no app CSRF).
 * Resolves on a 2xx, rejects otherwise. `onProgress` fires only when the whole-number
 * percent changes — a large upload emits hundreds of events but the UI needs at most 100.
 */
export function putWithProgress(
    url: string,
    headers: Record<string, string>,
    body: Blob,
    onProgress: (percent: number) => void,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        for (const [header, value] of Object.entries(headers)) {
            xhr.setRequestHeader(header, value);
        }

        let lastPct = -1;
        xhr.upload.onprogress = (e) => {
            if (!e.lengthComputable) {
                return;
            }
            const pct = Math.round((e.loaded / e.total) * 100);
            if (pct !== lastPct) {
                lastPct = pct;
                onProgress(pct);
            }
        };
        xhr.onload = () =>
            xhr.status >= 200 && xhr.status < 300
                ? resolve()
                : reject(new Error(`upload failed: ${xhr.status}`));
        xhr.onerror = () => reject(new Error('network error'));
        xhr.send(body);
    });
}

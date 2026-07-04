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

// --- Compression budget helpers ----------------------------------------
// Pure math shared by the compose/edit gates and the mediabunny encoder, so the
// "what size do we aim for" decision lives in exactly one place.

/** Audio is re-encoded at a fixed cap so the bitrate budget is predictable. */
const AUDIO_BITRATE = 128_000;
/** Headroom for container overhead + VBR drift, so we land under the cap. */
const SIZE_SAFETY = 0.9;
/** Never starve the video track below this, however tight the budget. */
const MIN_VIDEO_BITRATE = 300_000;
/** Cap the longest edge of a compressed video before bitrate even comes in. */
const RESOLUTION_CEILING = 1920;
/** Stop downscaling once the longest edge would drop below this. */
const DOWNSCALE_FLOOR = 480;
/** Each downscale step shrinks the longest edge by this factor. */
const DOWNSCALE_STEP = 0.8;

/** Re-export for the encoder's retry clamp (keep the floor in one place). */
export const VIDEO_MIN_BITRATE = MIN_VIDEO_BITRATE;

export type EncodePlan = {
    videoBitrate: number;
    audioBitrate: number;
    width: number;
    height: number;
};

/** Even and ≥ 2 — encoders reject odd dimensions. */
function toEven(value: number): number {
    return Math.max(2, Math.floor(value / 2) * 2);
}

/** Smallest video byte cap across the selected platforms (∞ when none selected). */
export function minVideoBytes(limits: PlatformLimits[]): number {
    return Math.min(
        ...limits.map((l) => l.maxVideoBytes),
        Number.POSITIVE_INFINITY,
    );
}

/**
 * Bitrate + dimension budget to re-encode `meta` under `maxBytes`. Pure: the
 * actual encode and its corrective retries live in the mediabunny chunk.
 */
export function planVideoEncode(meta: VideoMeta, maxBytes: number): EncodePlan {
    // Guard against a zero/garbage duration blowing up the division.
    const duration = Math.max(meta.durationSeconds, 1);
    const targetBits = maxBytes * 8 * SIZE_SAFETY;
    const videoBitrate = Math.max(
        Math.floor(targetBits / duration) - AUDIO_BITRATE,
        MIN_VIDEO_BITRATE,
    );

    const longest = Math.max(meta.width, meta.height);
    const scale =
        longest > RESOLUTION_CEILING ? RESOLUTION_CEILING / longest : 1;

    return {
        videoBitrate,
        audioBitrate: AUDIO_BITRATE,
        width: toEven(meta.width * scale),
        height: toEven(meta.height * scale),
    };
}

/**
 * Next smaller even dimensions (longest edge −20%), or `null` once the longest
 * edge would fall below the floor — the signal to stop retrying. Mirrors
 * `ImageCompressor`'s quality-then-downscale fallback.
 */
export function nextDownscale(
    width: number,
    height: number,
): { width: number; height: number } | null {
    if (Math.max(width, height) * DOWNSCALE_STEP < DOWNSCALE_FLOOR) {
        return null;
    }

    return {
        width: toEven(width * DOWNSCALE_STEP),
        height: toEven(height * DOWNSCALE_STEP),
    };
}

export function readVideoMetadata(file: File): Promise<VideoMeta> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.muted = true;

        let settled = false;
        const finish = (run: () => void): void => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timer);
            run();
            URL.revokeObjectURL(url);
            video.removeAttribute('src');
            video.load();
        };

        const hasDimensions = (): boolean =>
            video.videoWidth > 0 && video.videoHeight > 0;
        const hasDuration = (): boolean =>
            Number.isFinite(video.duration) && video.duration > 0;

        const succeed = (): void =>
            finish(() =>
                resolve({
                    sizeBytes: file.size,
                    mime: file.type,
                    // Floor, not round: a 140.4s clip must not be rejected against a
                    // 140s cap. Clamp to ≥1: a valid video always has some duration,
                    // but a sub-second trim (e.g. 0.8s) floors to 0, which fails the
                    // confirm endpoint's `min:1` rule and 422s the upload.
                    durationSeconds: Math.max(1, Math.floor(video.duration)),
                    width: video.videoWidth,
                    height: video.videoHeight,
                }),
            );
        const bail = (): void =>
            finish(() => reject(new Error('Could not read video metadata.')));

        // A freshly-muxed MP4/WebM blob (e.g. the video editor's output) often
        // fires `loadedmetadata` before the browser has resolved the real
        // duration (reported as Infinity/NaN) or frame dimensions (0×0). Seeking
        // past the end forces it to scan the file and settle both; `seeked` then
        // carries trustworthy values. Only nudge when something is missing, so a
        // normal file resolves immediately without the extra work.
        const settle = (): void => {
            if (hasDuration() && hasDimensions()) {
                succeed();
            } else if (Number.isFinite(video.duration) && !hasDimensions()) {
                // Duration is known but dimensions aren't — decode one frame. Seek
                // to a small non-zero offset rather than 0: the element already
                // sits at currentTime 0, and assigning the current value is a no-op
                // that never fires `seeked`, so we'd hang to the timeout instead.
                video.currentTime = 1e-3;
            } else {
                video.currentTime = 1e101;
            }
        };

        video.onloadedmetadata = settle;
        video.onseeked = () => {
            if (hasDuration() && hasDimensions()) {
                succeed();
            } else {
                bail();
            }
        };
        video.onerror = bail;

        // Never hang the upload flow on a video the browser can't parse.
        const timer = setTimeout(bail, 15000);

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

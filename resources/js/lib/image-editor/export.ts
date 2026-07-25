import { toCanvas } from 'html-to-image';

import type { EditSettings } from './settings';

/**
 * Rasterizing to a lossless PNG (html-to-image's default) blows past the upload
 * cap for a photo — a ~3 MP photographic PNG is 6–12 MB against an 8 MiB limit —
 * so the composed upload was silently rejected with a 422 and the media never
 * attached (#126). Encode to a compressed format sized to fit instead.
 */
const MAX_COMPOSED_BYTES = Math.floor(7.5 * 1024 * 1024); // headroom under the 8 MiB (max:8192) server cap
const QUALITY_STEPS = [0.92, 0.82, 0.72, 0.6] as const;
const MIN_SCALE = 0.5;

/**
 * Pick a rasterization pixel-ratio: render at `baseScale` for crispness, but
 * cap the longest output edge at `maxEdge` so file size stays within platform
 * media limits.
 */
export function computeExportScale(
    longestEdgePx: number,
    maxEdge = 2048,
    baseScale = 2,
): number {
    if (longestEdgePx <= 0) {
        return baseScale;
    }
    const capped = maxEdge / longestEdgePx;

    return Math.min(baseScale, capped < baseScale ? capped : baseScale);
}

/**
 * Choose the composed encoding. A gradient background makes the composite fully
 * opaque, so JPEG — the smallest and universally encodable format — is safe.
 * Without a background the padding, corner radius or 3D tilt can expose
 * transparency, so WebP is used to keep the alpha channel (still far smaller than
 * PNG). Browsers that can't encode WebP fall the canvas back to PNG on their own.
 */
export function pickComposedFormat(settings: EditSettings): {
    type: string;
    quality: number;
} {
    return settings.background.type === 'none'
        ? { type: 'image/webp', quality: QUALITY_STEPS[0] }
        : { type: 'image/jpeg', quality: QUALITY_STEPS[0] };
}

function canvasToBlob(
    canvas: HTMLCanvasElement,
    type: string,
    quality?: number,
): Promise<Blob | null> {
    return new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type, quality);
    });
}

function scaleCanvas(
    source: HTMLCanvasElement,
    scale: number,
): HTMLCanvasElement {
    const out = document.createElement('canvas');
    out.width = Math.max(1, Math.round(source.width * scale));
    out.height = Math.max(1, Math.round(source.height * scale));
    const ctx = out.getContext('2d');
    if (ctx) {
        ctx.drawImage(source, 0, 0, out.width, out.height);
    }

    return out;
}

/**
 * Encode `canvas` to a blob at or under `maxBytes`: step the quality down at full
 * resolution first (to preserve detail), then downscale and retry, until it fits.
 * A browser that can't encode the requested lossy `type` returns a PNG instead —
 * that's still a valid upload, so it's accepted once it fits.
 */
export async function encodeCanvasToFit(
    canvas: HTMLCanvasElement,
    format: { type: string; quality: number },
    maxBytes: number,
): Promise<Blob> {
    for (let scale = 1; scale >= MIN_SCALE - 1e-6; scale -= 0.2) {
        const target = scale >= 1 ? canvas : scaleCanvas(canvas, scale);
        for (const quality of QUALITY_STEPS) {
            const blob = await canvasToBlob(target, format.type, quality);
            if (!blob) {
                break;
            }
            // A PNG fallback ignores quality, so retrying qualities is pointless —
            // drop to the next (smaller) scale as soon as it doesn't fit.
            if (blob.type !== format.type) {
                if (blob.size <= maxBytes) {
                    return blob;
                }
                break;
            }
            if (blob.size <= maxBytes) {
                return blob;
            }
        }
    }

    // Nothing fit within the scale/quality budget — return the smallest attempt so
    // the caller still uploads *something* rather than dropping the media entirely.
    const smallest = scaleCanvas(canvas, MIN_SCALE);
    const blob =
        (await canvasToBlob(smallest, format.type, 0.5)) ??
        (await canvasToBlob(smallest, 'image/png'));
    if (!blob) {
        throw new Error('Failed to rasterize the image.');
    }

    return blob;
}

/** Rasterize the stage DOM node to a compressed blob sized to fit the upload cap. */
export async function rasterizeStage(
    node: HTMLElement,
    naturalLongestEdge: number,
    settings: EditSettings,
    maxBytes: number = MAX_COMPOSED_BYTES,
): Promise<Blob> {
    // The stage has no text, so skip web-font embedding entirely. It is the step
    // that fails: html-to-image reads every stylesheet's cssRules to inline
    // @font-face, which throws a SecurityError on cross-origin sheets (the Vite
    // dev server serves CSS from a different origin than the app) and mis-parses
    // url() backgrounds — aborting the whole rasterization.
    const canvas = await toCanvas(node, {
        pixelRatio: computeExportScale(naturalLongestEdge),
        skipFonts: true,
    });

    return encodeCanvasToFit(canvas, pickComposedFormat(settings), maxBytes);
}

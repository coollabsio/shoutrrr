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
/** Downscale factors tried in order, ending at the smallest we'll ship. */
const SCALE_STEPS = [1, 0.8, 0.6, 0.5] as const;

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
 * Encode `target` at the requested lossy `type`, falling back to PNG when the
 * browser can't produce that type (older Safari can't encode canvas WebP — it
 * either returns a PNG blob or `null`). Returns `null` only if even PNG fails.
 */
async function encodeCanvas(
    target: HTMLCanvasElement,
    type: string,
    quality: number,
): Promise<Blob | null> {
    const blob = await canvasToBlob(target, type, quality);
    if (blob) {
        return blob;
    }

    return type === 'image/png' ? null : canvasToBlob(target, 'image/png');
}

/**
 * Encode `canvas` to a blob at or under `maxBytes`: step the quality down at full
 * resolution first (to preserve detail), then downscale and retry, until it fits.
 * A browser that can't encode the requested lossy `type` produces a PNG instead —
 * still a valid upload, accepted once it fits. Throws when nothing fits so the
 * editor surfaces its "couldn't process" error rather than uploading a file the
 * server will reject with the very 422 this guards against (#126).
 */
export async function encodeCanvasToFit(
    canvas: HTMLCanvasElement,
    format: { type: string; quality: number },
    maxBytes: number,
): Promise<Blob> {
    // Sweep from the caller's chosen starting quality downward, so
    // pickComposedFormat is the single source of truth for where encoding begins.
    const qualitySteps = QUALITY_STEPS.filter((q) => q <= format.quality);
    for (const scale of SCALE_STEPS) {
        const target = scale >= 1 ? canvas : scaleCanvas(canvas, scale);
        for (const quality of qualitySteps) {
            const blob = await encodeCanvas(target, format.type, quality);
            if (!blob) {
                break;
            }
            if (blob.size <= maxBytes) {
                return blob;
            }
            // A PNG fallback ignores quality, so retrying qualities won't shrink it
            // — drop straight to the next (smaller) scale.
            if (blob.type !== format.type) {
                break;
            }
        }
    }

    throw new Error('Failed to rasterize the image.');
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

import { toBlob } from 'html-to-image';

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

/** Rasterize the stage DOM node to a PNG blob. */
export async function rasterizeStage(
    node: HTMLElement,
    naturalLongestEdge: number,
): Promise<Blob> {
    const blob = await toBlob(node, {
        pixelRatio: computeExportScale(naturalLongestEdge),
        // The stage has no text, so skip web-font embedding entirely. It is the
        // step that fails: html-to-image reads every stylesheet's cssRules to
        // inline @font-face, which throws a SecurityError on cross-origin sheets
        // (the Vite dev server serves CSS from a different origin than the app)
        // and mis-parses url() backgrounds — aborting the whole rasterization.
        skipFonts: true,
    });
    if (!blob) {
        throw new Error('Failed to rasterize the image.');
    }

    return blob;
}

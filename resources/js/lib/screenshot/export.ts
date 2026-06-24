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
        cacheBust: true,
    });
    if (!blob) {
        throw new Error('Failed to rasterize the screenshot.');
    }

    return blob;
}

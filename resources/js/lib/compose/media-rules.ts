import type { MediaView } from '@/types/compose';

/** Matches the app-wide GIF convention: any `image/gif` is treated as animated. */
const GIF_MIME = 'image/gif';

/**
 * Whether an image is attach-only rather than editable. The raster beautifier
 * would flatten an animation to a single frame, so animated media must never
 * open the editor. Animation isn't visible from the mime alone — Klipy stores a
 * "GIF" as the larger `image/webp` variant, the same mime the beautifier itself
 * emits — so we distinguish by provenance: a WebP is editable only when it's a
 * beautifier output (it carries `edit_settings`). A WebP without `edit_settings`
 * is a GIF-browser animation (or a raw upload we can't prove is static), so it's
 * treated as attach-only. GIFs are always animated.
 */
export function isAttachOnlyImage(
    media: Pick<MediaView, 'mime' | 'edit_settings'>,
): boolean {
    if (media.mime === GIF_MIME) {
        return true;
    }

    return media.mime === 'image/webp' && media.edit_settings === null;
}

/**
 * A post carries one video OR images, never both. Given the already-attached
 * media and an incoming batch, returns whether the batch would mix the two — a
 * video joining images, or images joining a video.
 */
export function wouldMixVideoAndImages(
    existing: Pick<MediaView, 'kind'>[],
    incoming: File[],
): boolean {
    const videos = incoming.filter((f) => f.type.startsWith('video/'));
    const images = incoming.filter((f) => !f.type.startsWith('video/'));
    const hasVideo = existing.some((m) => m.kind === 'video');
    const hasImage = existing.some((m) => m.kind !== 'video');

    return (
        (videos.length > 0 && (images.length > 0 || hasImage)) ||
        (images.length > 0 && hasVideo)
    );
}

/**
 * Bluesky allows one animated GIF per post and won't mix it with other media.
 * Given the already-attached media and an incoming batch, returns whether the
 * resulting set would break that rule — one predicate covering every branch:
 * GIF + other media, other media + existing GIF, and a second GIF.
 */
export function wouldViolateBlueskyGif(
    existing: Pick<MediaView, 'mime'>[],
    incoming: File[],
): boolean {
    const gifTotal =
        existing.filter((m) => m.mime === GIF_MIME).length +
        incoming.filter((f) => f.type === GIF_MIME).length;
    const total = existing.length + incoming.length;

    return gifTotal >= 1 && (total > 1 || gifTotal > 1);
}

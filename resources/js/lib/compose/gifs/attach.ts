import { xsrfHeader } from '@/lib/csrf';
import type { MediaView } from '@/types/compose';
import type { GifItem } from '@/types/gifs';

/**
 * POST a chosen GIF/sticker/clip to an attach endpoint and return the media row
 * the server downloaded and re-hosted.
 *
 * Shared by the composer and the engagement reply box: the two differ only in
 * which endpoint they post to, so the request shape, the XSRF header and the
 * error unwrapping live here rather than being maintained twice.
 *
 * `mediaIds` is what the caller currently holds attached. The server treats it
 * as the "existing media" set for the mixing-rule guard — it re-resolves the
 * ids workspace-scoped, so a client cannot widen its own permissions with them.
 */
export async function postGifAttachment(
    url: string,
    item: GifItem,
    mediaIds: string[],
): Promise<MediaView> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...xsrfHeader(),
        },
        body: JSON.stringify({
            catalog: item.catalog,
            slug: item.slug,
            title: item.title,
            variants: item.variants,
            media_ids: mediaIds,
        }),
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as {
            message?: string;
        };

        throw new Error(body.message ?? 'That GIF could not be attached.');
    }

    return ((await response.json()) as { media: MediaView }).media;
}

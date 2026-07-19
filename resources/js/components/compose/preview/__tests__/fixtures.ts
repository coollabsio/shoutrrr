import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type { MediaView, PlatformName, PostFormat } from '@/types/compose';

export function imageMedia(id: string): MediaView {
    return {
        id,
        url: `https://cdn.example.test/${id}.jpg`,
        mime: 'image/jpeg',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
    };
}

export function videoMedia(id: string): MediaView {
    return {
        id,
        url: `https://cdn.example.test/${id}.mp4`,
        mime: 'video/mp4',
        kind: 'video',
        alt_text: null,
        duration_seconds: 12,
        position: 0,
        edit_settings: null,
        source_url: null,
    };
}

export function makePreview(
    platform: PlatformName,
    media: MediaView[],
    caption = 'Sunset over the harbor #travel',
    format: PostFormat = 'feed',
): PlatformPreview {
    return {
        platform,
        accountName: 'Harbor Studio',
        accountHandle: '@harbor.studio',
        avatarUrl: null,
        limit: 2200,
        autoSplit: false,
        format,
        items: [
            {
                id: `${platform}-preview-1`,
                text: caption,
                media,
                count: caption.length,
                overLimit: false,
                linkExclusions: [],
            },
        ],
    };
}

import { platformLabel } from '@/lib/platforms';
import type { MediaView, PlatformName, PostFormat } from '@/types/compose';

export type FormatNotice = 'caption_dropped' | 'story_first_media_only';

export type AccountNotice = {
    accountId: string;
    handle: string;
    platform: PlatformName;
    notices: FormatNotice[];
};

type NoticeInput = {
    platform: PlatformName;
    format: PostFormat;
    hasText: boolean;
    mediaCount: number;
};

/**
 * Non-blocking, format-specific warnings for one account. Stories drop the
 * caption (Meta accepts no text on story endpoints) and publish only their
 * first media item.
 */
export function formatNoticesForAccount({
    format,
    hasText,
    mediaCount,
}: NoticeInput): FormatNotice[] {
    if (format !== 'story') {
        return [];
    }

    const notices: FormatNotice[] = [];
    if (hasText) {
        notices.push('caption_dropped');
    }
    if (mediaCount > 1) {
        notices.push('story_first_media_only');
    }

    return notices;
}

export function describeFormatNotice(
    notice: FormatNotice,
    platform: PlatformName,
): string {
    const label = platformLabel(platform);
    switch (notice) {
        case 'caption_dropped':
            return `Stories don't support captions — your text won't be posted to ${label}. It'll still post everywhere else.`;
        case 'story_first_media_only':
            return `Only the first item will post to your ${label} Story.`;
    }
}

type NoticesInput = {
    accounts: {
        id: string;
        handle: string;
        platform: PlatformName;
    }[];
    segments: string[];
    overrideByAccount: Record<string, string[] | undefined>;
    formatByAccount: Record<string, PostFormat>;
    media: MediaView[];
};

/** Per-account non-blocking notices across the destination. */
export function precheckNotices({
    accounts,
    segments,
    overrideByAccount,
    formatByAccount,
    media,
}: NoticesInput): AccountNotice[] {
    const out: AccountNotice[] = [];
    for (const account of accounts) {
        const accountSegments = overrideByAccount[account.id] ?? segments;
        const hasText = accountSegments.join('').trim().length > 0;
        const notices = formatNoticesForAccount({
            platform: account.platform,
            format: formatByAccount[account.id] ?? 'feed',
            hasText,
            mediaCount: media.length,
        });
        if (notices.length > 0) {
            out.push({
                accountId: account.id,
                handle: account.handle,
                platform: account.platform,
                notices,
            });
        }
    }

    return out;
}

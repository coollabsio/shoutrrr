import { replaceMentionTokens } from '@/lib/compose/mentions';
import { measure } from '@/lib/compose/section-split';
import { platformLabel } from '@/lib/platforms';
import type {
    Account,
    MediaView,
    MentionPlaceholder,
    PlatformLimits,
    PlatformName,
    PostFormat,
} from '@/types/compose';

export type BlockReason =
    | 'empty'
    | 'media_required'
    | 'section_too_long'
    | 'too_many_sections'
    | 'too_many_media'
    | 'mixed_video_and_images'
    | 'video_too_long'
    | 'video_too_large'
    | 'gif_not_mixable'
    | 'reels_requires_video'
    | 'story_requires_media';

export type AccountBlock = {
    accountId: string;
    handle: string;
    platform: PlatformName;
    reasons: BlockReason[];
};

type PrecheckAccountInput = {
    account: Account;
    segments: string[];
    autoSplit: boolean;
    mentions: MentionPlaceholder[];
    mediaCount: number;
    hasVideo: boolean;
    format: PostFormat;
    limits: PlatformLimits;
};

function byteLength(text: string): number {
    return new TextEncoder().encode(text).length;
}

/**
 * Blocking reasons for one account, mirroring the sections the server's
 * PostSplitter will actually store:
 *  - no text and no media: nothing to post, so `empty` is the only reason —
 *    the length/media checks below are meaningless on it.
 *  - media-first platform (requiresMedia): text alone is rejected by the
 *    platform, so a caption with no attachment blocks as `media_required`.
 *  - thread-capped platform (threadMax !== null): all segments collapse into a
 *    single joined section.
 *  - non-capped + auto-split ON: the server hard-splits any over-limit paragraph
 *    down to word/char, so every stored section fits by length — length never
 *    blocks (a rare byte-budget survivor is caught by the server backstop).
 *  - non-capped + auto-split OFF: stored sections are the raw trimmed segments.
 */
export function precheckAccount({
    account,
    segments,
    autoSplit,
    mentions,
    mediaCount,
    hasVideo,
    format,
    limits,
}: PrecheckAccountInput): BlockReason[] {
    const reasons: BlockReason[] = [];
    const clean = segments
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '');

    if (clean.length === 0 && mediaCount === 0) {
        return ['empty'];
    }

    const capped = limits.threadMax !== null;
    const sections = capped ? [clean.join('\n')] : autoSplit ? [] : clean;

    const limit = account.max_text_length || limits.maxLength;
    const overLength = sections.some((section) => {
        const resolved = replaceMentionTokens(
            section,
            mentions,
            account.platform,
        );
        if (limit > 0 && measure(resolved, account.platform) > limit) {
            return true;
        }

        return (
            limits.maxBytes !== null && byteLength(resolved) > limits.maxBytes
        );
    });
    if (overLength) {
        reasons.push('section_too_long');
    }

    if (limits.threadMax !== null && sections.length > limits.threadMax) {
        reasons.push('too_many_sections');
    }

    if (mediaCount > limits.maxMedia) {
        reasons.push('too_many_media');
    }

    if (mediaCount === 0 && limits.requiresMedia) {
        reasons.push('media_required');
    }

    if (format === 'reels' && !hasVideo) {
        reasons.push('reels_requires_video');
    }
    if (format === 'story' && mediaCount === 0) {
        reasons.push('story_requires_media');
    }

    return reasons;
}

type PrecheckDestinationsInput = {
    accounts: Account[];
    segments: string[];
    mentions: MentionPlaceholder[];
    autoSplitByAccount: Record<string, boolean>;
    overrideByAccount: Record<string, string[] | undefined>;
    media: MediaView[];
    limits: PlatformLimits[];
    formatByAccount: Record<string, PostFormat>;
};

/**
 * Every target is judged against the FULL post media set, not a per-account
 * subset. The publish path (PublishPostTarget::context) hands each connector the
 * global `$post->media`, so that is what actually publishes; per-account media
 * exclusions are not honored server-side. Filtering the count here would let the
 * composer greenlight a destination the connector would then botch (e.g. dropping
 * silently-mixed images), and disagree with the server PublishPrecheck. Keep this
 * global so the client, the server precheck, and the connectors all agree.
 */
export function precheckDestinations({
    accounts,
    segments,
    mentions,
    autoSplitByAccount,
    overrideByAccount,
    media,
    limits,
    formatByAccount,
}: PrecheckDestinationsInput): AccountBlock[] {
    const blocks: AccountBlock[] = [];
    const mediaCount = media.length;
    const hasVideo = media.some((item) => item.kind === 'video');

    for (const account of accounts) {
        const platformLimits = limits.find(
            (item) => item.platform === account.platform,
        );
        if (!platformLimits) {
            continue;
        }
        const accountSegments = overrideByAccount[account.id] ?? segments;
        const reasons = precheckAccount({
            account,
            segments: accountSegments,
            autoSplit: autoSplitByAccount[account.id] ?? true,
            mentions,
            mediaCount,
            hasVideo,
            format: formatByAccount[account.id] ?? 'feed',
            limits: platformLimits,
        });
        if (reasons.length > 0) {
            blocks.push({
                accountId: account.id,
                handle: account.handle,
                platform: account.platform,
                reasons,
            });
        }
    }

    return blocks;
}

export function describeReason(
    reason: BlockReason,
    platform: PlatformName,
    limits: PlatformLimits,
): string {
    const label = platformLabel(platform);
    switch (reason) {
        case 'empty':
            return 'add some text or media before publishing';
        case 'media_required':
            return `${label} needs at least one image or video`;
        case 'section_too_long': {
            const base = `over ${label}'s ${limits.maxLength.toLocaleString()}-character limit`;

            return limits.threadMax === null
                ? `${base} — shorten it or turn on auto-split`
                : base;
        }
        case 'too_many_sections': {
            const max = limits.threadMax ?? 1;

            return `${label} allows only ${max} post${max === 1 ? '' : 's'} — remove thread breaks`;
        }
        case 'too_many_media':
            return `${label} allows only ${limits.maxMedia} media item${limits.maxMedia === 1 ? '' : 's'}`;
        case 'mixed_video_and_images':
            return 'a post can contain one video or images, not both';
        case 'video_too_long':
            return `the video is longer than ${label}'s ${limits.maxVideoDurationSeconds}s limit`;
        case 'video_too_large':
            return `the video is larger than ${label}'s ${Math.floor(limits.maxVideoBytes / (1024 * 1024))} MB limit`;
        case 'gif_not_mixable':
            return `${label} allows only one GIF and won't mix it with other media`;
        case 'reels_requires_video':
            return `${label} Reels need a video`;
        case 'story_requires_media':
            return `${label} Stories need an image or video`;
    }
}

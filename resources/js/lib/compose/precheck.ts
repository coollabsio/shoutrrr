import { replaceMentionTokens } from '@/lib/compose/mentions';
import { measure } from '@/lib/compose/section-split';
import { platformLabel } from '@/lib/platforms';
import type {
    Account,
    GoogleBusinessProfileLocalPostOptions,
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
    | 'story_requires_media'
    | 'gbp_summary_required'
    | 'gbp_threads_not_supported'
    | 'gbp_media_not_supported'
    | 'gbp_event_title_required'
    | 'gbp_event_schedule_required'
    | 'gbp_offer_title_required'
    | 'gbp_offer_schedule_required'
    | 'gbp_offer_redemption_url_required'
    | 'gbp_cta_url_invalid'
    | 'gbp_unsafe_url'
    | 'gbp_phone_stuffing'
    | 'gbp_regulated_promotion'
    | 'gbp_hotel_promotion'
    | 'gbp_repetitive_content';

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
    providerOptions?: GoogleBusinessProfileLocalPostOptions;
};

function hasValidGoogleBusinessProfileSchedule(
    options: GoogleBusinessProfileLocalPostOptions,
): boolean {
    const start = Date.parse(options.start_at ?? '');
    const end = Date.parse(options.end_at ?? '');

    return !Number.isNaN(start) && !Number.isNaN(end) && end > start;
}

function isInvalidGoogleBusinessProfileUrl(value: string | undefined): boolean {
    if (!value || !URL.canParse(value)) {
        return true;
    }

    const url = new URL(value);
    const hostname = url.hostname.toLowerCase();

    return (
        url.protocol !== 'https:' ||
        /^\d{1,3}(\.\d{1,3}){3}$/.test(hostname) ||
        ['bit.ly', 'tinyurl.com', 't.co'].includes(hostname)
    );
}

function normalizedGoogleBusinessProfileCtaType(
    value: string | undefined,
): string | undefined {
    const trimmed = value?.trim();

    return trimmed ? trimmed.toUpperCase() : undefined;
}

function normalizedGoogleBusinessProfileCtaUrl(
    value: string | undefined,
): string | undefined {
    const trimmed = value?.trim();

    return trimmed || undefined;
}

function googleBusinessProfilePolicyReasons(
    text: string,
    localPostType: GoogleBusinessProfileLocalPostOptions['local_post_type'],
): BlockReason[] {
    const reasons: BlockReason[] = [];
    const urls = text.match(/https?:\/\/[^\s<]+/gi) ?? [];
    if (urls.some((url) => isInvalidGoogleBusinessProfileUrl(url))) {
        reasons.push('gbp_unsafe_url');
    }
    if ((text.match(/(?:\+?\d[\d().\-\s]{6,}\d)/g) ?? []).length > 1) {
        reasons.push('gbp_phone_stuffing');
    }
    const promotional = /\b(discount|deal|offer|promotion|sale|coupon)\b/i.test(
        text,
    );
    if (
        promotional &&
        /\b(alcohol|cannabis|marijuana|tobacco|vape|gambling|casino|firearm|weapon|adult)\b/i.test(
            text,
        )
    ) {
        reasons.push('gbp_regulated_promotion');
    }
    if (
        /\b(hotel|motel|resort|inn)\b/i.test(text) &&
        (localPostType === 'offer' || promotional)
    ) {
        reasons.push('gbp_hotel_promotion');
    }

    const sentences = (text.toLowerCase().match(/[^.!?]+[.!?]?/g) ?? [])
        .map((sentence) => sentence.trim())
        .filter(Boolean);
    if (new Set(sentences).size !== sentences.length) {
        reasons.push('gbp_repetitive_content');
    }

    return reasons;
}

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
    providerOptions,
}: PrecheckAccountInput): BlockReason[] {
    const reasons: BlockReason[] = [];
    const clean = segments
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '');

    if (account.platform === 'google_business_profile') {
        if (clean.length === 0) {
            reasons.push('gbp_summary_required');
        }
        if (clean.length > 1) {
            reasons.push('gbp_threads_not_supported');
        }
        if (mediaCount > 0) {
            reasons.push('gbp_media_not_supported');
        }

        const type = providerOptions?.local_post_type ?? 'standard';
        reasons.push(
            ...googleBusinessProfilePolicyReasons(clean.join('\n'), type),
        );
        const ctaType = normalizedGoogleBusinessProfileCtaType(
            providerOptions?.cta_type,
        );
        const ctaUrl = normalizedGoogleBusinessProfileCtaUrl(
            providerOptions?.cta_url,
        );
        if (
            (ctaType && ctaType !== 'CALL' && !ctaUrl) ||
            (ctaUrl && isInvalidGoogleBusinessProfileUrl(ctaUrl))
        ) {
            reasons.push('gbp_cta_url_invalid');
        }
        if (type === 'event' || type === 'offer') {
            if (!providerOptions?.title?.trim()) {
                reasons.push(`gbp_${type}_title_required` as BlockReason);
            }
            if (
                !providerOptions ||
                !hasValidGoogleBusinessProfileSchedule(providerOptions)
            ) {
                reasons.push(`gbp_${type}_schedule_required` as BlockReason);
            }
            if (
                type === 'offer' &&
                isInvalidGoogleBusinessProfileUrl(
                    providerOptions?.redemption_url,
                )
            ) {
                reasons.push('gbp_offer_redemption_url_required');
            }
        }

        return reasons;
    }

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
    providerOptionsByAccount?: Record<
        string,
        GoogleBusinessProfileLocalPostOptions | undefined
    >;
};

/**
 * Every target is judged against the FULL post media set (whole-post counts),
 * not a per-segment breakdown. Per-segment media is placed per thread post
 * (post_media_placements) and rides its segment's first sub-post, but the
 * server's PublishPrecheck and the connectors still enforce media caps and the
 * video/image rule per WHOLE post (each connector uploads and caps against the
 * full `$post->media`). Counting per-segment here would let the composer
 * greenlight a post the server then blocks or truncates. Keep this whole-post
 * so the client, the server precheck, and the connectors all agree; per-segment
 * limits move here only once the server enforces them per section.
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
    providerOptionsByAccount = {},
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
            providerOptions: providerOptionsByAccount[account.id],
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
        case 'gbp_summary_required':
            return 'Google Business Profile requires a post summary';
        case 'gbp_threads_not_supported':
            return 'Google Business Profile does not support threaded posts';
        case 'gbp_media_not_supported':
            return 'Google Business Profile media is not supported in this release';
        case 'gbp_event_title_required':
        case 'gbp_offer_title_required':
            return 'this local post needs a title';
        case 'gbp_event_schedule_required':
        case 'gbp_offer_schedule_required':
            return 'this local post needs a valid start and end time';
        case 'gbp_offer_redemption_url_required':
            return 'this offer needs a valid redemption URL';
        case 'gbp_cta_url_invalid':
            return 'Google Business Profile CTA URLs must be safe HTTPS URLs';
        case 'gbp_unsafe_url':
            return 'post text contains an unsafe URL';
        case 'gbp_phone_stuffing':
            return 'post text contains too many phone numbers';
        case 'gbp_regulated_promotion':
            return 'promotional content cannot advertise regulated products or services';
        case 'gbp_hotel_promotion':
            return 'hotels cannot publish offers, deals, or promotions';
        case 'gbp_repetitive_content':
            return 'post text appears repetitive or spam-like';
    }
}

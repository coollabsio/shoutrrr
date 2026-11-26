import type {
    PlatformName,
    TikTokCreatorState,
    TikTokOptions,
    TikTokPrivacyLevel,
} from '@/types/compose';

/**
 * TikTok's Content Sharing Guidelines defaults: no privacy level chosen, every
 * interaction off, and no commercial content disclosure. Mirrors
 * App\Dto\Post\TikTokPostOptions on the server.
 */
export const DEFAULT_TIKTOK_OPTIONS: TikTokOptions = {
    post_mode: 'direct',
    privacy_level: null,
    allow_comment: false,
    allow_duet: false,
    allow_stitch: false,
    disclose_commercial: false,
    brand_organic: false,
    branded_content: false,
};

/** TikTok's own wording for an incomplete disclosure (Content Sharing Guidelines 3a). */
export const DISCLOSURE_INCOMPLETE_HINT =
    'You need to indicate if your content promotes yourself, a third party, or both.';

/** TikTok's own wording for branded content set to private (Content Sharing Guidelines 3b). */
export const BRANDED_CONTENT_PRIVATE_HINT =
    'Branded content visibility cannot be set to private.';

export const MUSIC_USAGE_URL =
    'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en';

export const BRANDED_CONTENT_POLICY_URL =
    'https://www.tiktok.com/legal/page/global/bc-policy/en';

export type TikTokBlockReason =
    | 'tiktok_creator_unavailable'
    | 'tiktok_privacy_required'
    | 'tiktok_privacy_unavailable'
    | 'tiktok_interaction_disabled'
    | 'tiktok_video_too_long'
    | 'tiktok_disclosure_incomplete';

export type TikTokPrecheck = {
    options: TikTokOptions;
    /** Live creator info; undefined until it has been requested. */
    creator?: TikTokCreatorState;
    /** The attached video's length, or null for a photo post / unknown length. */
    videoDurationSeconds: number | null;
    isPhotoPost: boolean;
};

/**
 * Why a Direct Post can't be published yet. Inbox drafts carry none of these
 * choices — the creator finishes them in the TikTok app — so they never block.
 * The server re-checks every one of these against fresh creator info at publish.
 */
export function tikTokBlockReasons({
    options,
    creator,
    videoDurationSeconds,
    isPhotoPost,
}: TikTokPrecheck): TikTokBlockReason[] {
    if (options.post_mode !== 'direct') {
        return [];
    }

    const reasons: TikTokBlockReason[] = [];

    if (creator?.status === 'unavailable') {
        reasons.push('tiktok_creator_unavailable');
    }

    if (options.privacy_level === null) {
        reasons.push('tiktok_privacy_required');
    }

    if (creator?.status === 'available') {
        const info = creator.creator;

        if (
            options.privacy_level !== null &&
            !info.privacy_levels.some(
                (level) => level.value === options.privacy_level,
            )
        ) {
            reasons.push('tiktok_privacy_unavailable');
        }

        const interactionOff =
            (options.allow_comment && info.comment_disabled) ||
            (!isPhotoPost &&
                ((options.allow_duet && info.duet_disabled) ||
                    (options.allow_stitch && info.stitch_disabled)));
        if (interactionOff) {
            reasons.push('tiktok_interaction_disabled');
        }

        if (
            videoDurationSeconds !== null &&
            info.max_video_duration_seconds > 0 &&
            videoDurationSeconds > info.max_video_duration_seconds
        ) {
            reasons.push('tiktok_video_too_long');
        }
    }

    if (hasIncompleteDisclosure(options)) {
        reasons.push('tiktok_disclosure_incomplete');
    }

    return reasons;
}

/**
 * Commercial disclosure is switched on but neither "Your brand" nor "Branded
 * content" is ticked; TikTok won't take a Direct Post in that state.
 */
export function hasIncompleteDisclosure(options: TikTokOptions): boolean {
    return (
        options.disclose_commercial &&
        !options.brand_organic &&
        !options.branded_content
    );
}

/**
 * The label TikTok puts on the post for the chosen disclosure: branded content
 * (alone or with "Your brand") is a paid partnership, "Your brand" alone is
 * promotional content.
 */
export function disclosureLabel(options: TikTokOptions): string | null {
    if (!options.disclose_commercial) {
        return null;
    }
    if (options.branded_content) {
        return 'Paid partnership';
    }
    if (options.brand_organic) {
        return 'Promotional content';
    }

    return null;
}

export function isSelfOnly(level: TikTokPrivacyLevel | null): boolean {
    return level === 'SELF_ONLY';
}

/**
 * A TikTok target handed to the creator's inbox as a draft to finish in the
 * TikTok app, rather than posted. Mirrors `PostTarget::wentToTikTokInbox()`.
 */
export function sentToTikTokInbox(target: {
    platform: PlatformName;
    tiktok?: TikTokOptions | null;
}): boolean {
    return target.platform === 'tiktok' && target.tiktok?.post_mode === 'inbox';
}

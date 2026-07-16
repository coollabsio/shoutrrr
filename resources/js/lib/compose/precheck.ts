import { replaceMentionTokens } from '@/lib/compose/mentions';
import { measure } from '@/lib/compose/section-split';
import { platformLabel } from '@/lib/platforms';
import type {
    Account,
    MediaView,
    MentionPlaceholder,
    PlatformLimits,
    PlatformName,
} from '@/types/compose';

export type BlockReason =
    | 'section_too_long'
    | 'too_many_sections'
    | 'too_many_media';

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
    limits: PlatformLimits;
};

function byteLength(text: string): number {
    return new TextEncoder().encode(text).length;
}

/**
 * Blocking reasons for one account, mirroring the sections the server's
 * PostSplitter will actually store:
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
    limits,
}: PrecheckAccountInput): BlockReason[] {
    const reasons: BlockReason[] = [];
    const clean = segments
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '');

    const capped = limits.threadMax !== null;
    const sections = capped
        ? [clean.join('\n')]
        : autoSplit
          ? []
          : clean;

    const limit = account.max_text_length || limits.maxLength;
    const overLength = sections.some((section) => {
        const resolved = replaceMentionTokens(section, mentions, account.platform);
        if (limit > 0 && measure(resolved, account.platform) > limit) {
            return true;
        }

        return limits.maxBytes !== null && byteLength(resolved) > limits.maxBytes;
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

    return reasons;
}

type PrecheckDestinationsInput = {
    accounts: Account[];
    segments: string[];
    mentions: MentionPlaceholder[];
    autoSplitByAccount: Record<string, boolean>;
    overrideByAccount: Record<string, string[] | undefined>;
    media: MediaView[];
    mediaSubsetExcludes: Set<string>;
    limits: PlatformLimits[];
};

export function precheckDestinations({
    accounts,
    segments,
    mentions,
    autoSplitByAccount,
    overrideByAccount,
    media,
    mediaSubsetExcludes,
    limits,
}: PrecheckDestinationsInput): AccountBlock[] {
    const blocks: AccountBlock[] = [];

    for (const account of accounts) {
        const platformLimits = limits.find(
            (item) => item.platform === account.platform,
        );
        if (!platformLimits) {
            continue;
        }
        const accountSegments = overrideByAccount[account.id] ?? segments;
        const mediaCount = media.filter(
            (item) => !mediaSubsetExcludes.has(`${item.id}:${account.id}`),
        ).length;
        const reasons = precheckAccount({
            account,
            segments: accountSegments,
            autoSplit: autoSplitByAccount[account.id] ?? true,
            mentions,
            mediaCount,
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
    }
}

import {
    discordMentionLabels,
    mentionInputValue,
    replaceMentionTokens,
} from '@/lib/compose/mentions';
import { collapsePlatformNewlines } from '@/lib/compose/platform-newlines';
import { measure, packSections } from '@/lib/compose/section-split';
import { segmentRefs } from '@/lib/compose/tiptap-doc';
import type {
    Account,
    MediaView,
    MentionPlaceholder,
    PlatformName,
    PostFormat,
} from '@/types/compose';

export type PlatformPreviewItem = {
    id: string;
    text: string;
    media: MediaView[];
    count: number;
    overLimit: boolean;
    linkExclusions: string[];
};

export type PlatformPreview = {
    platform: PlatformName;
    accountName: string;
    accountHandle: string;
    avatarUrl: string | null;
    limit: number;
    autoSplit: boolean;
    /**
     * The surface this account publishes to. Drives which preview the panel
     * renders for Instagram/Facebook (feed post, reel, or story); every other
     * platform is always `feed`.
     */
    format: PostFormat;
    items: PlatformPreviewItem[];
    /** Discord snowflake id → display label, for rendering `<@id>` as a pill. */
    discordLabels: Record<string, string>;
};

type BuildPlatformPreviewInput = {
    account: Account;
    segments: string[];
    mentions: MentionPlaceholder[];
    media: MediaView[];
    excludedMediaIds: Set<string>;
    limit: number;
    autoSplit: boolean;
    /** Publishing surface; only Instagram/Facebook use anything but `feed`. */
    format?: PostFormat;
    /**
     * Per-segment media: `segmentRef -> ordered media ids` for the previewed
     * account's scope, plus the thread's break ids. When given, each media
     * renders under the thread post it's placed on (riding the first resolved
     * sub-post of its authored segment, as the server resolver does). When
     * omitted, all media falls on the first post (legacy behavior).
     */
    placements?: Record<string, string[]>;
    segmentBreaks?: string[];
};

/** A resolved preview section paired with the authored segment it came from. */
type BuiltSection = { text: string; source: number };

/**
 * Build the resolved preview sections while tracking which authored segment
 * each came from, so per-segment media can be attached to the right post.
 */
function buildSections(
    resolvedSegments: string[],
    platform: PlatformName,
    limit: number,
    autoSplit: boolean,
): BuiltSection[] {
    if (platform === 'linkedin') {
        return [
            {
                text: resolvedSegments
                    .map((s) => s.trim())
                    .filter((s) => s !== '')
                    .join('\n'),
                source: 0,
            },
        ];
    }
    if (!autoSplit) {
        return resolvedSegments.map((text, source) => ({ text, source }));
    }
    const out: BuiltSection[] = [];
    resolvedSegments.forEach((segment, source) => {
        const trimmed = segment.trim();
        if (trimmed === '') {
            return;
        }
        for (const section of packSections(
            trimmed.split('\n'),
            platform,
            limit,
        )) {
            out.push({ text: section.text, source });
        }
    });

    return out.length > 0 ? out : [{ text: '', source: 0 }];
}

/**
 * Distribute each authored segment's placed media onto the first resolved
 * section it produced — mirroring the server's SegmentMediaResolver, including
 * the fallback to the nearest earlier section (else the first) when a
 * media-bearing segment produced no section of its own.
 */
function distributeMedia(
    built: BuiltSection[],
    mediaBySegment: MediaView[][],
): MediaView[][] {
    const firstSection: Record<number, number> = {};
    built.forEach((section, index) => {
        if (firstSection[section.source] === undefined) {
            firstSection[section.source] = index;
        }
    });
    const sectionFor = (authored: number): number => {
        if (firstSection[authored] !== undefined) {
            return firstSection[authored];
        }
        for (let i = authored - 1; i >= 0; i--) {
            if (firstSection[i] !== undefined) {
                return firstSection[i];
            }
        }

        return 0;
    };

    const perSection: MediaView[][] = built.map(() => []);
    mediaBySegment.forEach((items, authored) => {
        if (items.length > 0) {
            perSection[sectionFor(authored)]?.push(...items);
        }
    });

    return perSection;
}

export function buildPlatformPreview({
    account,
    segments,
    mentions,
    media,
    excludedMediaIds,
    limit,
    autoSplit,
    format = 'feed',
    placements,
    segmentBreaks,
}: BuildPlatformPreviewInput): PlatformPreview {
    const resolvedSegments = segments.map((segment) =>
        replaceMentionTokens(segment, mentions, account.platform),
    );
    const built = buildSections(
        resolvedSegments,
        account.platform,
        limit,
        autoSplit,
    );
    const visibleMedia = media.filter((item) => !excludedMediaIds.has(item.id));

    // Per-segment distribution when placements are provided; otherwise every
    // media item falls on the first post (legacy behavior).
    let mediaPerSection: MediaView[][];
    if (placements === undefined) {
        mediaPerSection = built.map((_, index) =>
            index === 0 ? visibleMedia : [],
        );
    } else {
        const byId = new Map(visibleMedia.map((m) => [m.id, m]));
        const refs = segmentRefs(segmentBreaks ?? []);
        const mediaBySegment = resolvedSegments.map((_, i) =>
            (placements[refs[i] ?? '__head__'] ?? [])
                .map((id) => byId.get(id))
                .filter((m): m is MediaView => m !== undefined),
        );
        mediaPerSection = distributeMedia(built, mediaBySegment);
    }

    const linkExclusions =
        account.platform === 'linkedin'
            ? mentions
                  .map((mention) => mention.handles.linkedin ?? mention.label)
                  .map(mentionInputValue)
                  .filter((mention) => mention !== '')
            : [];

    return {
        platform: account.platform,
        accountName: account.display_name ?? account.handle,
        accountHandle: account.handle,
        avatarUrl: account.avatar_url,
        limit,
        autoSplit,
        format,
        discordLabels: discordMentionLabels(mentions),
        items: built.map((section, index) => ({
            id: `${account.platform}-preview-${index + 1}`,
            // Show the spacing the platform will actually render; the character
            // budget below still measures the raw text that gets transmitted.
            text: collapsePlatformNewlines(section.text, account.platform),
            media: mediaPerSection[index] ?? [],
            count: measure(section.text, account.platform),
            overLimit:
                limit > 0 && measure(section.text, account.platform) > limit,
            linkExclusions,
        })),
    };
}

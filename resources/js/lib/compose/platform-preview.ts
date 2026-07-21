import {
    mentionInputValue,
    replaceMentionTokens,
} from '@/lib/compose/mentions';
import { collapsePlatformNewlines } from '@/lib/compose/platform-newlines';
import { measure, previewSections } from '@/lib/compose/section-split';
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
};

export function buildPlatformPreview({
    account,
    segments,
    mentions,
    media,
    excludedMediaIds,
    limit,
    autoSplit,
    format = 'feed',
}: BuildPlatformPreviewInput): PlatformPreview {
    const resolvedSegments = segments.map((segment) =>
        replaceMentionTokens(segment, mentions, account.platform),
    );
    const sections =
        account.platform === 'linkedin'
            ? [
                  resolvedSegments
                      .map((s) => s.trim())
                      .filter((s) => s !== '')
                      .join('\n'),
              ]
            : autoSplit
              ? previewSections(resolvedSegments, account.platform, limit)
              : resolvedSegments;
    const visibleMedia = media.filter((item) => !excludedMediaIds.has(item.id));
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
        items: sections.map((section, index) => ({
            id: `${account.platform}-preview-${index + 1}`,
            // Show the spacing the platform will actually render; the character
            // budget below still measures the raw text that gets transmitted.
            text: collapsePlatformNewlines(section, account.platform),
            media: index === 0 ? visibleMedia : [],
            count: measure(section, account.platform),
            overLimit: limit > 0 && measure(section, account.platform) > limit,
            linkExclusions,
        })),
    };
}

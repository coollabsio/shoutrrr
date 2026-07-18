import type {
    MentionHandles,
    PlatformName,
    WorkspaceMention,
} from '@/types/compose';

export type MentionPlaceholder = {
    id: string;
    label: string;
    handles: MentionHandles;
};

const PLATFORMS: PlatformName[] = [
    'x',
    'bluesky',
    'linkedin',
    'facebook',
    'instagram',
    'threads',
];
// Platforms whose posts auto-link a bare `@handle`, so a real @mention is worth
// offering alongside plain text. Facebook is intentionally excluded: its post
// API does not auto-link `@text`, so a "mention" there would publish literally.
const MENTION_PLATFORMS = new Set<PlatformName>([
    'x',
    'bluesky',
    'instagram',
    'threads',
]);
const HANDLE_PATTERN = /(^|\s)@([a-zA-Z0-9_.-]{0,50})(?=\s|$|[.,!?;:])/g;

export function createMention(label: string): MentionPlaceholder {
    const normalizedLabel = normalizeMentionName(label);

    return {
        id: mentionIdFromLabel(normalizedLabel),
        label: normalizedLabel,
        handles: Object.fromEntries(
            PLATFORMS.map((platform) => [
                platform,
                mentionTextInput(platform, normalizedLabel),
            ]),
        ) as Record<PlatformName, string>,
    };
}

export function mentionToken(id: string): string {
    return `{{mention:${id}}}`;
}

export function updateMentionName(
    mention: MentionPlaceholder,
    name: string,
): MentionPlaceholder {
    const label = normalizeMentionName(name);
    const previousText = mentionInputValue(mention.label);
    const handles = Object.fromEntries(
        Object.entries(mention.handles).map(([platform, handle]) => [
            platform,
            // `linkedin_urn` is an org reference, never a display-name-derived
            // handle, so keep it verbatim even if it happens to match the label.
            platform !== 'linkedin_urn' &&
            (handle === mention.label ||
                handle === previousText ||
                (mention.label === '@' && handle === ''))
                ? mentionTextInput(platform as PlatformName, label)
                : handle,
        ]),
    ) as MentionHandles;

    return {
        ...mention,
        id: mentionIdFromLabel(label),
        label,
        handles,
    };
}

export function updateMentionHandle(
    mention: MentionPlaceholder,
    platform: PlatformName,
    handle: string,
    useMention = true,
): MentionPlaceholder {
    const handles = { ...mention.handles };
    // Keep an emptied field as '' rather than deleting the key, so the editor
    // input stays blank instead of snapping back to the mention-name fallback
    // (`handles[platform] ?? label`). Saving is gated separately.
    //
    // Pass the raw `handle` (not a trimmed copy) into `mentionTextInput`: this is
    // a controlled input, so trimming on every keystroke would eat the trailing
    // space between words, stranding a plain-text name like "Acme Corp" at
    // "AcmeCorp". A whitespace-only value still counts as empty.
    handles[platform] =
        handle.trim() === ''
            ? ''
            : mentionTextInput(platform, handle, useMention);

    return { ...mention, handles };
}

/** Whether a platform supports a real `@` mention (vs. plain display text only). */
export function platformSupportsMention(platform: PlatformName): boolean {
    return MENTION_PLATFORMS.has(platform);
}

/**
 * True when any active platform's display/handle field has been cleared. Used to
 * block saving a half-filled mention to the workspace while still letting the
 * empty field be used in the current post.
 */
export function hasEmptyActiveHandle(
    mention: MentionPlaceholder,
    platforms: PlatformName[],
): boolean {
    return platforms.some(
        (platform) =>
            mentionInputValue(
                mention.handles[platform] ?? mention.label,
            ).trim() === '',
    );
}

/**
 * Set (or clear) the non-platform `linkedin_urn` handle key — the raw LinkedIn
 * company URL / numeric id / `urn:li:organization:ID` used to emit a real
 * LinkedIn @tag at publish time. The value is stored verbatim (trimmed); the
 * server normalizes it into a canonical URN on save.
 */
export function updateMentionLinkedInUrn(
    mention: MentionPlaceholder,
    value: string,
): MentionPlaceholder {
    const handles = { ...mention.handles };
    const trimmed = value.trim();
    if (trimmed === '') {
        delete handles.linkedin_urn;
    } else {
        handles.linkedin_urn = trimmed;
    }

    return { ...mention, handles };
}

/**
 * Coerce a user-supplied LinkedIn reference into a canonical org URN, or null
 * when it cannot be resolved to a numeric organization id without an API lookup.
 * Mirrors {@link \App\Support\LinkedInOrg::normalizeUrn} exactly.
 *
 * Accepts `urn:li:organization:<digits>`, a bare `<digits>`, or a
 * `linkedin.com/company/<digits>` URL. A vanity slug returns null.
 */
export function normalizeLinkedInUrn(reference: string): string | null {
    const trimmed = reference.trim();
    if (trimmed === '') {
        return null;
    }

    const urnMatch = trimmed.match(/^urn:li:organization:(\d+)$/);
    if (urnMatch) {
        return `urn:li:organization:${urnMatch[1]}`;
    }

    if (/^\d+$/.test(trimmed)) {
        return `urn:li:organization:${trimmed}`;
    }

    const urlMatch = trimmed.match(/linkedin\.com\/company\/(\d+)/i);
    if (urlMatch) {
        return `urn:li:organization:${urlMatch[1]}`;
    }

    return null;
}

const LINKEDIN_URN_TOKEN = /urn:li:organization:\d+/;
const LINKEDIN_COMPANY_TOKEN = /\S*linkedin\.com\/company\/([^\s/?#]+)\S*/i;

/**
 * Pull an explicit LinkedIn org reference out of free text typed into the
 * display-name field. Only a `urn:li:organization:<digits>` token or a
 * `linkedin.com/company/<slug>` URL counts — a bare number is treated as
 * display text (mirrors {@link \App\Support\LinkedInOrg::looksLikeReference}).
 *
 * Returns the canonical `urn` (numeric company URL / urn), a `vanity` slug when
 * the company URL has no numeric id, and `rest` = the value with the matched
 * token stripped out and whitespace tidied.
 */
export function extractLinkedInOrgRef(value: string): {
    urn: string | null;
    vanity: string | null;
    rest: string;
} {
    const urnMatch = value.match(LINKEDIN_URN_TOKEN);
    if (urnMatch) {
        return {
            urn: normalizeLinkedInUrn(urnMatch[0]),
            vanity: null,
            rest: stripLinkedInToken(value, urnMatch.index ?? 0, urnMatch[0]),
        };
    }

    const companyMatch = value.match(LINKEDIN_COMPANY_TOKEN);
    if (companyMatch) {
        const slug = companyMatch[1];
        const rest = stripLinkedInToken(
            value,
            companyMatch.index ?? 0,
            companyMatch[0],
        );

        if (/^\d+$/.test(slug)) {
            return { urn: `urn:li:organization:${slug}`, vanity: null, rest };
        }

        return { urn: null, vanity: slug, rest };
    }

    return { urn: null, vanity: null, rest: value };
}

/** Remove a matched org-reference token and collapse the whitespace seam. */
function stripLinkedInToken(
    value: string,
    index: number,
    token: string,
): string {
    return `${value.slice(0, index)}${value.slice(index + token.length)}`
        .replace(/\s{2,}/g, ' ')
        .trim();
}

export function usesPlatformMention(
    mention: MentionPlaceholder,
    platform: PlatformName,
): boolean {
    return (
        MENTION_PLATFORMS.has(platform) &&
        (mention.handles[platform] ?? mention.label).startsWith('@')
    );
}

export function setPlatformMentionMode(
    mention: MentionPlaceholder,
    platform: PlatformName,
    useMention: boolean,
): MentionPlaceholder {
    const current = mention.handles[platform] ?? mention.label;

    return updateMentionHandle(
        mention,
        platform,
        mentionInputValue(current),
        useMention && MENTION_PLATFORMS.has(platform),
    );
}

function mentionTextInput(
    platform: PlatformName,
    value: string,
    useMention = true,
): string {
    return useMention && MENTION_PLATFORMS.has(platform)
        ? normalizeMentionName(value)
        : mentionInputValue(value);
}

export function syncMentionsFromText(
    text: string,
    current: MentionPlaceholder[],
    savedMentions: WorkspaceMention[] = [],
): MentionPlaceholder[] {
    const existing = new Map(
        current.map((mention) => [mention.label, mention]),
    );
    const saved = new Map(
        savedMentions.map((mention) => [mention.name, mention]),
    );
    const labels = [...new Set(extractMentionLabels(text))];

    return labels.map((label, index) => {
        const currentMention = existing.get(label);
        if (currentMention) {
            return currentMention;
        }

        const incompleteMention = current[index];
        if (incompleteMention?.label === '@' && label !== '@') {
            return updateMentionName(incompleteMention, label);
        }

        const savedMention = saved.get(label);
        if (savedMention) {
            return savedMentionToPlaceholder(savedMention);
        }

        return createMention(label);
    });
}

export function replaceMentionTokens(
    text: string,
    mentions: MentionPlaceholder[],
    platform: PlatformName,
): string {
    let replaced = text;

    for (const mention of [...mentions].sort(
        (left, right) => right.label.length - left.label.length,
    )) {
        replaced = replaced.replaceAll(
            mention.label,
            mentionTextOutput(mention, platform),
        );
    }

    const byId = new Map(mentions.map((mention) => [mention.id, mention]));

    return replaced.replaceAll(
        /\{\{mention:([a-zA-Z0-9_-]+)\}\}/g,
        (token, id) => {
            const mention = byId.get(id);

            return mention ? mentionTextOutput(mention, platform) : token;
        },
    );
}

function mentionTextOutput(
    mention: MentionPlaceholder,
    platform: PlatformName,
): string {
    const handle = mention.handles[platform] ?? mention.label;

    return platform === 'linkedin' ? mentionInputValue(handle) : handle;
}

function extractMentionLabels(text: string): string[] {
    const labels: string[] = [];

    for (const match of text.matchAll(HANDLE_PATTERN)) {
        labels.push(`@${match[2]}`);
    }

    return labels;
}

export function normalizeMentionName(name: string): string {
    const trimmed = name.trim().replace(/\s+/g, '-');

    return trimmed.startsWith('@') ? trimmed : `@${trimmed}`;
}

export function mentionInputValue(name: string): string {
    return name.replace(/^@/, '');
}

/**
 * Replace whole-token occurrences of `fromLabel` with `toLabel`. A label that is
 * only a substring of a longer handle is left alone — so renaming the in-progress
 * `@` mention does not rewrite the `@` inside an existing `@handle`.
 */
export function replaceMentionLabel(
    text: string,
    fromLabel: string,
    toLabel: string,
): string {
    if (fromLabel === '' || fromLabel === toLabel) {
        return text;
    }

    let result = '';
    let cursor = 0;
    for (
        let index = text.indexOf(fromLabel);
        index !== -1;
        index = text.indexOf(fromLabel, cursor)
    ) {
        const before = index === 0 ? '' : text[index - 1];
        const after = text[index + fromLabel.length] ?? '';
        result += text.slice(cursor, index);
        result += isMentionTokenBoundary(before, after) ? toLabel : fromLabel;
        cursor = index + fromLabel.length;
    }

    return result + text.slice(cursor);
}

/** Punctuation HANDLE_PATTERN permits immediately after a mention handle. */
export const MENTION_BOUNDARY_PUNCTUATION = '.,!?;:';

/** A char that can precede a mention token: the token start ('') or whitespace. */
export function startsMentionBoundary(char: string): boolean {
    return char === '' || /\s/.test(char);
}

/**
 * A char that can close a mention token: the token end (''), whitespace, or the
 * punctuation HANDLE_PATTERN permits after a handle.
 */
export function endsMentionBoundary(char: string): boolean {
    return (
        char === '' ||
        /\s/.test(char) ||
        MENTION_BOUNDARY_PUNCTUATION.includes(char)
    );
}

/**
 * A mention spans a whole token when it is preceded by the start or whitespace
 * and followed by the end, whitespace, or the punctuation HANDLE_PATTERN allows.
 */
function isMentionTokenBoundary(before: string, after: string): boolean {
    return startsMentionBoundary(before) && endsMentionBoundary(after);
}

/** Stable mention id from a workspace library name or label. */
function mentionIdFromLabel(label: string): string {
    const id = label
        .replace(/^@/, '')
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '-');

    return id || crypto.randomUUID();
}

export function savedMentionToPlaceholder(
    saved: WorkspaceMention,
): MentionPlaceholder {
    return {
        id: mentionIdFromLabel(saved.name),
        label: saved.name,
        handles: saved.handles,
    };
}

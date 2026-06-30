import type { PlatformName, WorkspaceMention } from '@/types/compose';

export type MentionPlaceholder = {
    id: string;
    label: string;
    handles: Partial<Record<PlatformName, string>>;
};

const PLATFORMS: PlatformName[] = ['x', 'bluesky', 'linkedin'];
const MENTION_PLATFORMS = new Set<PlatformName>(['x', 'bluesky']);
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
            handle === mention.label ||
            handle === previousText ||
            (mention.label === '@' && handle === '')
                ? mentionTextInput(platform as PlatformName, label)
                : handle,
        ]),
    ) as Partial<Record<PlatformName, string>>;

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
    const trimmed = handle.trim();
    if (trimmed === '') {
        delete handles[platform];
    } else {
        handles[platform] = mentionTextInput(platform, trimmed, useMention);
    }

    return { ...mention, handles };
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

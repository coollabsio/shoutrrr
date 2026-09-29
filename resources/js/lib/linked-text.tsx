import type { ReactNode } from 'react';

import type { PlatformName } from '@/types/compose';

export type LinkedTextPart =
    | { type: 'text'; text: string }
    | { type: 'link'; text: string; href: string }
    | { type: 'mention'; text: string };

type LinkCandidate = {
    start: number;
    end: number;
    text: string;
    /** A linkable target. Omitted for non-link spans (e.g. Discord mentions). */
    href?: string;
};

const URL_PATTERN =
    /(?<![@A-Za-z0-9._-])((?:https?:\/\/)?(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?:\/[^\s<]*)?)(?![A-Za-z0-9_-])/gi;

const X_MENTION_PATTERN =
    /(?<![A-Za-z0-9_])@[A-Za-z0-9_]{1,15}(?![A-Za-z0-9_])/g;

const BLUESKY_MENTION_PATTERN =
    /(?<![A-Za-z0-9._-])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z][A-Za-z0-9.-]*)(?![A-Za-z0-9._-])/g;

// Instagram usernames: up to 30 letters/numbers/periods/underscores, not part
// of an email local part (guarded by the lookbehind). A trailing period is
// trimmed back into the surrounding text since a handle can't end in one.
const INSTAGRAM_MENTION_PATTERN = /(?<![A-Za-z0-9._@])@([A-Za-z0-9._]{1,30})/g;

// Hashtags on Instagram and Facebook: a `#` at a word boundary followed by
// letters/numbers/underscores. The lookbehind skips `#` inside a word (e.g.
// `C#`) or an HTML entity (`&#39;`), matching how the apps detect tags.
const HASHTAG_PATTERN = /(?<![A-Za-z0-9_&])#([A-Za-z0-9_]+)/g;

// Discord's native mention markup: `<@id>`/`<@!id>` (user), `<@&id>` (role),
// `<#id>` (channel). The published/preview text stores the raw markup because
// that is what Discord receives; we render it as a friendly pill.
const DISCORD_MENTION_PATTERN = /<(@&|@!?|#)(\d+)>/g;

// LinkedIn's inline company annotation `@[Name](urn:li:organization:ID)` — the
// form baked into a published section. Render the name as a link to the company
// page (numeric org ids resolve at linkedin.com/company/<id>).
const LINKEDIN_ANNOTATION_PATTERN =
    /@\[([^\]]+)\]\(urn:li:organization:(\d+)\)/g;

function urlCandidates(
    text: string,
    linkExclusions: readonly string[],
): LinkCandidate[] {
    return [...text.matchAll(URL_PATTERN)]
        .map((match) => {
            const rawLink = match[1] ?? '';
            const linkText = rawLink.replace(/[.,!?;:]+$/, '');
            const start = match.index ?? 0;

            return {
                start,
                end: start + linkText.length,
                text: linkText,
                href: /^https?:\/\//i.test(linkText)
                    ? linkText
                    : `https://${linkText}`,
            };
        })
        .filter((candidate) => !linkExclusions.includes(candidate.text));
}

function mentionCandidates(
    text: string,
    platform?: PlatformName,
    discordLabels: Record<string, string> = {},
): LinkCandidate[] {
    // Discord mentions resolve `<@id>`/`<@&id>`/`<#id>` back to the friendly
    // name via the post's mention map. An id with no known label is left as raw
    // text rather than shown as a fake handle.
    if (platform === 'discord') {
        return [...text.matchAll(DISCORD_MENTION_PATTERN)].flatMap((match) => {
            const prefix = match[1] ?? '';
            const label = discordLabels[match[2] ?? ''];
            if (label === undefined) {
                return [];
            }
            const start = match.index ?? 0;

            return [
                {
                    start,
                    end: start + (match[0]?.length ?? 0),
                    text:
                        (prefix === '#' ? '#' : '@') +
                        label.replace(/^[@#]/, ''),
                },
            ];
        });
    }

    if (platform === 'linkedin') {
        return [...text.matchAll(LINKEDIN_ANNOTATION_PATTERN)].map((match) => {
            const start = match.index ?? 0;

            return {
                start,
                end: start + (match[0]?.length ?? 0),
                text: match[1] ?? '',
                href: `https://www.linkedin.com/company/${match[2]}`,
            };
        });
    }

    if (platform === 'x') {
        return [...text.matchAll(X_MENTION_PATTERN)].map((match) => {
            const handle = match[0] ?? '';
            const start = match.index ?? 0;

            return {
                start,
                end: start + handle.length,
                text: handle,
                href: `https://x.com/${handle.slice(1)}`,
            };
        });
    }

    if (platform === 'bluesky') {
        return [...text.matchAll(BLUESKY_MENTION_PATTERN)].map((match) => {
            const handle = match[0] ?? '';
            const start = match.index ?? 0;

            return {
                start,
                end: start + handle.length,
                text: handle,
                href: `https://bsky.app/profile/${handle.slice(1)}`,
            };
        });
    }

    // Instagram and Threads share the same username shape (a Threads account is
    // an Instagram account); only the profile host differs.
    if (platform === 'instagram' || platform === 'threads') {
        return [...text.matchAll(INSTAGRAM_MENTION_PATTERN)]
            .map((match) => {
                const handle = (match[0] ?? '').replace(/\.+$/, '');
                const start = match.index ?? 0;

                return {
                    start,
                    end: start + handle.length,
                    text: handle,
                    href:
                        platform === 'instagram'
                            ? `https://www.instagram.com/${handle.slice(1)}/`
                            : `https://www.threads.net/${handle}`,
                };
            })
            .filter((candidate) => candidate.text.length > 1);
    }

    return [];
}

/**
 * `#hashtag` links for the platforms that surface them (Instagram, Facebook,
 * Threads). Every other platform returns none, so their rendering is unchanged.
 */
function hashtagCandidates(
    text: string,
    platform?: PlatformName,
): LinkCandidate[] {
    const toHref =
        platform === 'instagram'
            ? (tag: string) => `https://www.instagram.com/explore/tags/${tag}`
            : platform === 'facebook'
              ? (tag: string) => `https://www.facebook.com/hashtag/${tag}`
              : platform === 'threads'
                ? (tag: string) =>
                      `https://www.threads.net/search?q=${tag}&serp_type=tags`
                : null;

    if (toHref === null) {
        return [];
    }

    return [...text.matchAll(HASHTAG_PATTERN)].map((match) => {
        const tag = match[0] ?? '';
        const start = match.index ?? 0;

        return {
            start,
            end: start + tag.length,
            text: tag,
            href: toHref((match[1] ?? '').toLowerCase()),
        };
    });
}

export function linkedTextParts(
    text: string,
    platform?: PlatformName,
    linkExclusions: readonly string[] = [],
    discordLabels: Record<string, string> = {},
): LinkedTextPart[] {
    const parts: LinkedTextPart[] = [];
    const candidates = [
        ...urlCandidates(text, linkExclusions),
        ...mentionCandidates(text, platform, discordLabels),
        ...hashtagCandidates(text, platform),
    ]
        .filter((candidate) => candidate.text !== '')
        .sort((left, right) => left.start - right.start);
    let cursor = 0;

    for (const candidate of candidates) {
        if (candidate.start < cursor) {
            continue;
        }

        if (candidate.start > cursor) {
            parts.push({
                type: 'text',
                text: text.slice(cursor, candidate.start),
            });
        }

        parts.push(
            candidate.href !== undefined
                ? { type: 'link', text: candidate.text, href: candidate.href }
                : { type: 'mention', text: candidate.text },
        );

        cursor = candidate.end;
    }

    if (cursor < text.length) {
        parts.push({ type: 'text', text: text.slice(cursor) });
    }

    return parts.length > 0 ? parts : [{ type: 'text', text }];
}

export function LinkedText({
    text,
    platform,
    linkExclusions = [],
    discordLabels = {},
    emptyFallback = null,
    linkClassName = 'font-medium text-primary underline underline-offset-2 hover:text-primary/80',
    mentionClassName = 'rounded bg-[#5865F2]/15 px-1 font-medium text-[#5865F2]',
}: {
    text: string;
    platform?: PlatformName;
    linkExclusions?: readonly string[];
    /**
     * Maps a Discord snowflake id to the mention's display label so `<@id>`
     * markup renders as a friendly pill instead of the raw id.
     */
    discordLabels?: Record<string, string>;
    emptyFallback?: ReactNode;
    /**
     * Overrides the styling of every linked span. Defaults to the app's
     * underlined link treatment; the Instagram/Facebook previews pass an
     * un-underlined blue so captions read like the real feeds.
     */
    linkClassName?: string;
    /** Styling for non-link mention pills (Discord). Defaults to a blurple pill. */
    mentionClassName?: string;
}) {
    if (text === '') {
        return emptyFallback;
    }

    return linkedTextParts(text, platform, linkExclusions, discordLabels).map(
        (part, index) => {
            if (part.type === 'text') {
                return part.text;
            }

            if (part.type === 'mention') {
                return (
                    <span key={`mention-${index}`} className={mentionClassName}>
                        {part.text}
                    </span>
                );
            }

            return (
                <a
                    key={`${part.href}-${index}`}
                    href={part.href}
                    target="_blank"
                    rel="noreferrer noopener"
                    className={linkClassName}
                >
                    {part.text}
                </a>
            );
        },
    );
}

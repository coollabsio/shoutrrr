import type { ReactNode } from 'react';

import type { PlatformName } from '@/types/compose';

export type LinkedTextPart =
    | { type: 'text'; text: string }
    | { type: 'link'; text: string; href: string };

type LinkCandidate = {
    start: number;
    end: number;
    text: string;
    href: string;
};

const URL_PATTERN =
    /(?<![@A-Za-z0-9._-])((?:https?:\/\/)?(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?:\/[^\s<]*)?)(?![A-Za-z0-9_-])/gi;

const X_MENTION_PATTERN =
    /(?<![A-Za-z0-9_])@[A-Za-z0-9_]{1,15}(?![A-Za-z0-9_])/g;

const BLUESKY_MENTION_PATTERN =
    /(?<![A-Za-z0-9._-])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z][A-Za-z0-9.-]*)(?![A-Za-z0-9._-])/g;

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
): LinkCandidate[] {
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

    return [];
}

export function linkedTextParts(
    text: string,
    platform?: PlatformName,
    linkExclusions: readonly string[] = [],
): LinkedTextPart[] {
    const parts: LinkedTextPart[] = [];
    const candidates = [
        ...urlCandidates(text, linkExclusions),
        ...mentionCandidates(text, platform),
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

        parts.push({
            type: 'link',
            text: candidate.text,
            href: candidate.href,
        });

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
    emptyFallback = null,
}: {
    text: string;
    platform?: PlatformName;
    linkExclusions?: readonly string[];
    emptyFallback?: ReactNode;
}) {
    if (text === '') {
        return emptyFallback;
    }

    return linkedTextParts(text, platform, linkExclusions).map(
        (part, index) => {
            if (part.type === 'text') {
                return part.text;
            }

            return (
                <a
                    key={`${part.href}-${index}`}
                    href={part.href}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="font-medium text-primary underline underline-offset-2 hover:text-primary/80"
                >
                    {part.text}
                </a>
            );
        },
    );
}

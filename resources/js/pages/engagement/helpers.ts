import { dayjs } from '@/lib/datetime/dayjs';

import type { ReplyItem } from './types';

/** Compact relative time, e.g. "4m", "3h", "2d" — falls back to a short date. */
export function relativeTime(iso: string): string {
    const then = dayjs(iso);
    if (!then.isValid()) {
        return '';
    }
    const seconds = dayjs().diff(then, 'second');
    if (seconds < 60) {
        return 'now';
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `${minutes}m`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours}h`;
    }
    const days = Math.floor(hours / 24);
    if (days < 7) {
        return `${days}d`;
    }
    return then.format('MMM D');
}

/** Up to two uppercase initials from a display name or handle. */
export function initials(
    reply: Pick<ReplyItem, 'author_name' | 'author_handle'>,
): string {
    const source = (reply.author_name ?? reply.author_handle ?? '').trim();
    if (source === '') {
        return '?';
    }
    const parts = source.replace(/^@/, '').split(/\s+/).filter(Boolean);
    const letters =
        parts.length >= 2
            ? parts[0][0] + parts[1][0]
            : source.replace(/^@/, '').slice(0, 2);
    return letters.toUpperCase();
}

/** Display handle with a leading @ when it isn't already a URL-style handle. */
export function atHandle(handle: string | null): string {
    if (!handle) {
        return '';
    }
    return handle.startsWith('@') ? handle : `@${handle}`;
}

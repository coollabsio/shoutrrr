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

/** True when a keyboard event originated from an editable field. */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
        return true;
    }

    return Boolean(target.isContentEditable);
}

export type EngagementShortcut =
    | { type: 'next' }
    | { type: 'prev' }
    | { type: 'archive' }
    | { type: 'open' }
    | { type: 'reply' };

/**
 * Map a bare keypress to an engagement inbox shortcut.
 * Ignores modified keys and events from editable fields.
 */
export function engagementShortcut(
    event: Pick<KeyboardEvent, 'key'> &
        Partial<
            Pick<KeyboardEvent, 'metaKey' | 'ctrlKey' | 'altKey' | 'target'>
        >,
): EngagementShortcut | null {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return null;
    }

    if (event.target !== undefined && isTypingTarget(event.target)) {
        return null;
    }

    switch (event.key) {
        case 'ArrowDown':
            return { type: 'next' };
        case 'ArrowUp':
            return { type: 'prev' };
        case 'a':
        case 'A':
            return { type: 'archive' };
        case 'o':
        case 'O':
            return { type: 'open' };
        case 'r':
        case 'R':
            return { type: 'reply' };
        default:
            return null;
    }
}

/** Index of the item that should become selected after moving by `delta`. */
export function adjacentIndex(
    length: number,
    currentIndex: number,
    delta: 1 | -1,
): number {
    if (length === 0) {
        return -1;
    }

    if (currentIndex < 0) {
        return delta === 1 ? 0 : length - 1;
    }

    return Math.min(length - 1, Math.max(0, currentIndex + delta));
}

/**
 * After archiving `currentId`, pick the next triage target: the item that
 * followed it, or the previous one if it was last. Returns null when empty.
 */
export function nextAfterArchive(
    ids: readonly string[],
    currentId: string,
): string | null {
    const index = ids.indexOf(currentId);

    if (index === -1) {
        return ids[0] ?? null;
    }

    if (index + 1 < ids.length) {
        return ids[index + 1] ?? null;
    }

    if (index - 1 >= 0) {
        return ids[index - 1] ?? null;
    }

    return null;
}

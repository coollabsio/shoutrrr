import {
    initialsFrom,
    isBareShortcut,
    type ShortcutEvent,
} from '@/lib/inbox/helpers';

import type { ReplyItem } from './types';

export {
    actionErrorMessage,
    adjacentIndex,
    atHandle,
    nextAfterArchive,
    relativeTime,
} from '@/lib/inbox/helpers';

/** Up to two uppercase initials from a display name or handle. */
export function initials(
    reply: Pick<ReplyItem, 'author_name' | 'author_handle'>,
): string {
    return initialsFrom(reply.author_name, reply.author_handle);
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
    event: ShortcutEvent,
): EngagementShortcut | null {
    if (!isBareShortcut(event)) {
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

/**
 * Ids present in the freshly polled list but not in the one on screen. The
 * inbox holds these back behind a "show new replies" button rather than
 * reshuffling rows the reader is in the middle of triaging.
 */
export function unseenIds(
    onScreen: readonly { id: string }[],
    incoming: readonly { id: string }[],
): string[] {
    const seen = new Set(onScreen.map((item) => item.id));

    return incoming.filter((item) => !seen.has(item.id)).map((item) => item.id);
}

import { dayjs } from '@/lib/datetime/dayjs';
import {
    initialsFrom,
    isBareShortcut,
    type ShortcutEvent,
} from '@/lib/inbox/helpers';

import type { ConversationItem } from './types';

export {
    actionErrorMessage,
    adjacentIndex,
    atHandle,
    nextAfterArchive,
    relativeTime,
} from '@/lib/inbox/helpers';

/** Up to two uppercase initials from a counterpart's display name or handle. */
export function initials(
    conversation: Pick<
        ConversationItem,
        'counterpart_name' | 'counterpart_handle'
    >,
): string {
    return initialsFrom(
        conversation.counterpart_name,
        conversation.counterpart_handle,
    );
}

/**
 * Human-readable status of a Meta 24-hour messaging window: null when a
 * platform has no window concept (X/Bluesky), "closed" once expired, or a
 * rounded hour countdown while still open.
 */
export function windowLabel(windowExpiresAt: string | null): string | null {
    if (!windowExpiresAt) {
        return null;
    }
    const expires = dayjs(windowExpiresAt);
    if (!expires.isValid()) {
        return null;
    }

    const now = dayjs();
    if (!expires.isAfter(now)) {
        return 'Reply window closed';
    }

    const hours = Math.max(1, Math.round(expires.diff(now, 'minute') / 60));
    return `Reply window closes in ${hours}h`;
}

export type MessagesShortcut =
    | { type: 'next' }
    | { type: 'prev' }
    | { type: 'archive' }
    | { type: 'reply' };

/**
 * Map a bare keypress to a messages inbox shortcut.
 * Ignores modified keys and events from editable fields.
 */
export function messagesShortcut(
    event: ShortcutEvent,
): MessagesShortcut | null {
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
        case 'r':
        case 'R':
            return { type: 'reply' };
        default:
            return null;
    }
}

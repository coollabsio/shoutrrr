import { dayjs } from '@/lib/datetime/dayjs';

/**
 * Helpers shared by the triage inboxes (`pages/engagement`, `pages/messages`).
 * Both pages present the same two-pane list/desk with keyboard triage, so the
 * list maths, key guards, and error-body parsing live here once. Page-specific
 * shape (item field names, key sets, live polling) stays in the page's own
 * `helpers.ts`.
 */

/** Compact relative time, e.g. "4m", "3h", "2d" — falls back to a short date. */
export function relativeTime(iso: string | null): string {
    if (!iso) {
        return '';
    }
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

/**
 * Up to two uppercase initials from a display name, falling back to the handle.
 * Each page wraps this with its own item shape (`author_*`, `counterpart_*`).
 */
export function initialsFrom(
    name: string | null,
    handle: string | null,
): string {
    const source = (name ?? handle ?? '').trim();
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

/**
 * Message for a failed inbox action, read from `useHttp`'s `onHttpException`
 * response. That response carries the **raw body string**, and a non-2xx can
 * come from anywhere in the stack — a proxy's HTML 502 page must not throw
 * inside an error handler, so parsing is guarded and falls back.
 */
export function actionErrorMessage(
    response: { data: string },
    fallback: string,
): string {
    try {
        const parsed: unknown = JSON.parse(response.data);
        if (parsed !== null && typeof parsed === 'object') {
            const { message } = parsed as { message?: unknown };
            if (typeof message === 'string' && message.trim() !== '') {
                return message;
            }
        }
    } catch {
        // Not JSON (e.g. a gateway HTML error page) — use the fallback.
    }

    return fallback;
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

/** A keyboard event narrowed to what the inbox shortcut mappers read. */
export type ShortcutEvent = Pick<KeyboardEvent, 'key'> &
    Partial<Pick<KeyboardEvent, 'metaKey' | 'ctrlKey' | 'altKey' | 'target'>>;

/**
 * True when a keypress is a bare shortcut candidate: no modifier held and not
 * typed into an editable field. Each inbox maps its own key set on top.
 */
export function isBareShortcut(event: ShortcutEvent): boolean {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return false;
    }

    return event.target === undefined || !isTypingTarget(event.target);
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

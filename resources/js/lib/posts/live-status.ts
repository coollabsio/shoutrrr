import { dayjs, toUserTz } from '@/lib/datetime/dayjs';
import type { PostView } from '@/types/compose';

/**
 * A short status line for a post's detail header. For a scheduled post it
 * surfaces the exact date and time (in the scheduling timezone) followed by a
 * relative countdown, e.g. "Going live Sep 5, 2026 at 2:30 PM · in 3 days".
 * Returns null for statuses with no time worth surfacing (drafts, deleted),
 * where the editor itself is the relevant context.
 *
 * @param tz IANA timezone to render the scheduled date/time in; defaults to the
 *   browser tz when omitted.
 */
export function postLiveStatus(
    post: Pick<PostView, 'status' | 'scheduled_at' | 'published_at'>,
    tz?: string,
): string | null {
    switch (post.status) {
        case 'scheduled':
            return post.scheduled_at
                ? `Going live ${toUserTz(post.scheduled_at, tz).format('MMM D, YYYY [at] h:mm A')} · ${dayjs(post.scheduled_at).fromNow()}`
                : 'Scheduled';
        case 'publishing':
            return 'Publishing now…';
        case 'published':
            return post.published_at
                ? `Published ${dayjs(post.published_at).fromNow()}`
                : 'Published';
        case 'partial':
            return 'Partially published';
        case 'failed':
            return 'Failed to publish';
        case 'missed':
            return post.scheduled_at
                ? `Missed · was due ${dayjs(post.scheduled_at).fromNow()}`
                : 'Missed';
        default:
            return null;
    }
}

import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import type { PostRowData, PostStatus } from '@/components/posts/post-row';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { dayjs, toUserTz, weekRange } from '@/lib/datetime/dayjs';
import type { Dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';

/** Strip tint mirrors the desktop PostChip tones (sky / muted / destructive). */
function stripTone(status: PostStatus): string {
    if (status === 'failed' || status === 'partial') {
        return 'bg-destructive';
    }
    if (status === 'published') {
        return 'bg-muted-foreground/40';
    }

    return 'bg-sky-500 dark:bg-sky-400';
}

/** The calendar days the agenda spans for the active view. */
export function windowDays(anchor: Dayjs, view: 'month' | 'week'): Dayjs[] {
    if (view === 'week') {
        return weekRange(anchor).days;
    }
    const start = anchor.startOf('month');

    return Array.from({ length: anchor.daysInMonth() }, (_, i) =>
        start.add(i, 'day'),
    );
}

/** Bucket posts by their scheduled (or published) day in the user's timezone. */
export function postsByDay(
    posts: PostRowData[],
    tz: string,
): Map<string, PostRowData[]> {
    const byDay = new Map<string, PostRowData[]>();
    for (const post of posts) {
        const at = post.scheduled_at ?? post.published_at;
        if (!at) {
            continue;
        }
        const key = toUserTz(at, tz).format('YYYY-MM-DD');
        const bucket = byDay.get(key);
        if (bucket) {
            bucket.push(post);
        } else {
            byDay.set(key, [post]);
        }
    }

    return byDay;
}

type Props = {
    /** Month anchor (month view) or any day in the target week (week view). */
    anchor: Dayjs;
    view: 'month' | 'week';
    posts: PostRowData[];
    /** Open the composer pre-set to 09:00 on the given day. */
    onEmptyDayClick: (day: Dayjs) => void;
};

/**
 * Mobile calendar: a vertical agenda with a date rail on the left and each
 * day's posts on the right. Replaces the dense 7-column grids below `sm`. Days
 * are tap-to-open (posts) or tap-to-add (empty future days).
 */
export function AgendaList({ anchor, view, posts, onEmptyDayClick }: Props) {
    const tz = useSchedulingTimezone();
    const today = dayjs().tz(tz).startOf('day');
    const days = windowDays(anchor, view);
    const byDay = postsByDay(posts, tz);

    return (
        <ol className="py-2">
            {days.map((day) => {
                const key = day.format('YYYY-MM-DD');
                const dayPosts = (byDay.get(key) ?? [])
                    .slice()
                    .sort((a, b) =>
                        (a.scheduled_at ?? a.published_at ?? '').localeCompare(
                            b.scheduled_at ?? b.published_at ?? '',
                        ),
                    );
                const isToday = day.isSame(today, 'day');
                const isPast = day.isBefore(today, 'day');

                return (
                    <li key={key} className="flex gap-3 py-1">
                        <div className="flex w-11 shrink-0 flex-col items-center pt-1">
                            <span
                                className={cn(
                                    'text-[10.5px] font-medium tracking-wider uppercase',
                                    isToday
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {day.format('ddd')}
                            </span>
                            <span
                                className={cn(
                                    'mt-0.5 inline-flex h-7 min-w-7 items-center justify-center rounded-full px-1 text-[15px] font-semibold tabular-nums',
                                    isToday
                                        ? 'bg-primary text-primary-foreground'
                                        : isPast
                                          ? 'text-muted-foreground'
                                          : 'text-foreground',
                                )}
                            >
                                {day.format('D')}
                            </span>
                        </div>

                        <div className="min-w-0 flex-1 space-y-1 border-l border-border/60 py-0.5 pl-3">
                            {dayPosts.length > 0 ? (
                                dayPosts.map((post) => (
                                    <AgendaItem key={post.id} post={post} />
                                ))
                            ) : isPast ? (
                                <p className="px-1 py-2.5 text-[12.5px] text-muted-foreground/45">
                                    No posts
                                </p>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => onEmptyDayClick(day)}
                                    className="flex w-full items-center gap-1.5 rounded-lg px-2.5 py-2.5 text-left text-[12.5px] text-muted-foreground/70 transition-colors hover:bg-muted/60 hover:text-foreground"
                                >
                                    <Plus className="size-3.5" aria-hidden />
                                    Add post
                                </button>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function AgendaItem({ post }: { post: PostRowData }) {
    const tz = useSchedulingTimezone();
    const at = post.scheduled_at ?? post.published_at;
    const when = at ? toUserTz(at, tz).format('h:mm a') : '';
    const platform = (post.platforms ?? [])[0];

    return (
        <button
            type="button"
            onClick={() => router.visit(ComposerController.show(post.id).url)}
            className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2.5 text-left transition-colors hover:bg-muted/60 active:bg-muted"
            title={post.base_text}
        >
            <span
                aria-hidden
                className={cn(
                    'h-7 w-[3px] shrink-0 rounded-full',
                    stripTone(post.status),
                )}
            />
            <span className="w-16 shrink-0 text-[12px] text-muted-foreground tabular-nums">
                {when}
            </span>
            {platform && (
                <PlatformGlyph
                    platform={platform}
                    size={13}
                    className="shrink-0 opacity-70"
                />
            )}
            <span className="min-w-0 flex-1 truncate text-[13px]">
                {post.base_text || 'Untitled'}
            </span>
        </button>
    );
}

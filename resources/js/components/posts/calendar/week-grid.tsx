import { useDroppable } from '@dnd-kit/core';
import type { CSSProperties } from 'react';
import { useEffect, useState } from 'react';

import type { PostRowData } from '@/components/posts/post-row';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { dayjs, toUserTz, weekRange } from '@/lib/datetime/dayjs';
import type { Dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';

import { PostChip } from './post-chip';

/** Week drop: day @ hour:minute in the user tz. Returns ISO (UTC, Z). */
export function computeWeekDrop(
    dropDay: string,
    dropHour: number,
    tz: string,
    dropMinute = 0,
): string {
    return dayjs
        .tz(dropDay, 'YYYY-MM-DD', tz)
        .hour(dropHour)
        .minute(dropMinute)
        .second(0)
        .millisecond(0)
        .utc()
        .format('YYYY-MM-DDTHH:mm:ss[Z]');
}

const HOURS = Array.from({ length: 24 }, (_, h) => h);
const HOUR_HEIGHT_PX = 32;
const GUTTER_PX = 48;

/** Live target of an in-progress week drag: which day + the snapped time. */
export type WeekDropHint = { day: string; hour: number; minute: number };

export function WeekGrid({
    anchor,
    posts,
    onEmptyHourClick,
    dropHint,
}: {
    anchor: Dayjs;
    posts: PostRowData[];
    onEmptyHourClick: (day: Dayjs, hour: number) => void;
    dropHint?: WeekDropHint | null;
}) {
    const tz = useSchedulingTimezone();
    const now = useNow(tz);
    const today = now.startOf('hour');
    const { days } = weekRange(anchor);

    const byCell = new Map<string, PostRowData[]>();
    for (const p of posts) {
        const at = p.scheduled_at ?? p.published_at;
        if (!at) continue;
        const d = toUserTz(at, tz);
        const key = `${d.format('YYYY-MM-DD')}-${d.hour()}`;
        if (!byCell.has(key)) byCell.set(key, []);
        byCell.get(key)!.push(p);
    }

    const todayIdx = days.findIndex((d) => d.isSame(now, 'day'));
    const showNowLine = todayIdx >= 0;
    const nowOffsetPx = showNowLine
        ? (now.hour() + now.minute() / 60) * HOUR_HEIGHT_PX
        : 0;

    // Live drop preview while dragging: which day column + the snapped time.
    const dropIndex = dropHint
        ? days.findIndex((d) => d.format('YYYY-MM-DD') === dropHint.day)
        : -1;
    const dropTopPx = dropHint
        ? (dropHint.hour + dropHint.minute / 60) * HOUR_HEIGHT_PX
        : 0;
    const dropLabel = dropHint
        ? dayjs().hour(dropHint.hour).minute(dropHint.minute).format('h:mm A')
        : '';

    const gridStyle = {
        '--hour-h': `${HOUR_HEIGHT_PX}px`,
        '--gutter': `${GUTTER_PX}px`,
    } as CSSProperties;

    return (
        <div className="px-3 py-3" style={gridStyle}>
            <div className="sticky top-[42px] z-[5] mb-1 grid grid-cols-[var(--gutter)_repeat(7,_minmax(0,_1fr))] bg-background/85 backdrop-blur-md">
                <div />
                {days.map((d) => {
                    const isToday = d.isSame(now, 'day');
                    return (
                        <div
                            key={d.format('YYYY-MM-DD')}
                            className={cn(
                                'flex items-baseline justify-center gap-1.5 border-b border-border px-1 py-1.5 text-[10.5px] font-medium tracking-wider uppercase',
                                isToday
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <span>{d.format('ddd')}</span>
                            <span
                                className={cn(
                                    'inline-flex h-[18px] min-w-[18px] items-center justify-center text-[11px] tabular-nums',
                                    isToday &&
                                        'rounded-full bg-primary-gradient px-1 text-primary-foreground inset-shadow-[0_1px_0_0_var(--primary-gradient-highlight)]',
                                )}
                            >
                                {d.format('D')}
                            </span>
                        </div>
                    );
                })}
            </div>
            <div className="relative grid grid-cols-[var(--gutter)_repeat(7,_minmax(0,_1fr))] gap-px overflow-hidden rounded-lg border border-border bg-border">
                {HOURS.map((h) => (
                    <HourRow
                        key={h}
                        hour={h}
                        days={days}
                        byCell={byCell}
                        isPast={(d) => d.hour(h).isBefore(today)}
                        onEmptyClick={(d) => onEmptyHourClick(d, h)}
                    />
                ))}
                {showNowLine && (
                    <div
                        aria-hidden
                        className="pointer-events-none absolute z-10 flex items-center"
                        style={{
                            top: `${nowOffsetPx}px`,
                            left: `${GUTTER_PX}px`,
                            right: 0,
                        }}
                    >
                        <span className="-ml-1 size-2 rounded-full bg-destructive shadow-[0_0_0_2px_var(--background)]" />
                        <span className="h-px flex-1 bg-destructive/80" />
                    </div>
                )}
                {dropHint && dropIndex >= 0 && (
                    <div
                        aria-hidden
                        className="pointer-events-none absolute z-20 flex items-center"
                        style={{
                            top: `${dropTopPx}px`,
                            left: `calc(var(--gutter) + (100% - var(--gutter)) * ${dropIndex} / 7)`,
                            width: `calc((100% - var(--gutter)) / 7)`,
                        }}
                    >
                        <span className="-translate-y-1/2 rounded bg-primary-gradient px-1 py-0.5 text-[9px] leading-none font-semibold text-primary-foreground tabular-nums shadow-sm">
                            {dropLabel}
                        </span>
                        <span className="h-0.5 flex-1 rounded-full bg-primary" />
                    </div>
                )}
            </div>
        </div>
    );
}

function HourRow({
    hour,
    days,
    byCell,
    isPast,
    onEmptyClick,
}: {
    hour: number;
    days: Dayjs[];
    byCell: Map<string, PostRowData[]>;
    isPast: (d: Dayjs) => boolean;
    onEmptyClick: (d: Dayjs) => void;
}) {
    const label =
        hour === 0
            ? '12 AM'
            : hour === 12
              ? '12 PM'
              : hour < 12
                ? `${hour} AM`
                : `${hour - 12} PM`;
    return (
        <>
            <div className="flex h-[var(--hour-h)] items-start justify-end bg-background px-1.5 pt-0.5 text-[10px] font-medium tracking-wider text-muted-foreground/80 uppercase tabular-nums">
                {hour === 0 ? '' : label}
            </div>
            {days.map((d) => (
                <HourCell
                    key={`${d.format('YYYY-MM-DD')}-${hour}`}
                    day={d}
                    hour={hour}
                    past={isPast(d)}
                    posts={
                        byCell.get(`${d.format('YYYY-MM-DD')}-${hour}`) ?? []
                    }
                    onEmptyClick={() => onEmptyClick(d)}
                />
            ))}
        </>
    );
}

function HourCell({
    day,
    hour,
    past,
    posts,
    onEmptyClick,
}: {
    day: Dayjs;
    hour: number;
    past: boolean;
    posts: PostRowData[];
    onEmptyClick: () => void;
}) {
    const { setNodeRef, isOver } = useDroppable({
        id: `cell-${day.format('YYYY-MM-DD')}-${hour}`,
        data: { day: day.format('YYYY-MM-DD'), hour },
        disabled: past,
    });
    const empty = posts.length === 0;
    return (
        <div
            ref={setNodeRef}
            // oxlint-disable-next-line prefer-tag-over-role -- droppable container needs the div ref from useDroppable
            role="button"
            tabIndex={past ? -1 : 0}
            aria-label={`${day.format('ddd D')} hour ${hour}`}
            onClick={() => {
                if (empty && !past) onEmptyClick();
            }}
            onKeyDown={(e) => {
                if ((e.key === 'Enter' || e.key === ' ') && empty && !past) {
                    e.preventDefault();
                    onEmptyClick();
                }
            }}
            className={cn(
                'group/cell relative min-h-[var(--hour-h)] cursor-pointer bg-background p-0.5 transition-colors',
                'focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset',
                past && 'cursor-default bg-muted/40',
                empty && !past && 'hover:bg-accent/40',
                isOver && 'ring-2 ring-primary/60 ring-inset',
            )}
        >
            <div className={cn('space-y-0.5', past && 'opacity-50')}>
                {posts.map((p) => (
                    <PostChip
                        key={p.id}
                        post={p}
                        draggable={!past && p.status === 'scheduled'}
                    />
                ))}
            </div>
        </div>
    );
}

function useNow(tz: string): Dayjs {
    const [now, setNow] = useState(() => dayjs().tz(tz));
    useEffect(() => {
        const id = window.setInterval(() => setNow(dayjs().tz(tz)), 60_000);
        return () => window.clearInterval(id);
    }, [tz]);
    return now;
}

import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    pointerWithin,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragMoveEvent,
} from '@dnd-kit/core';
import { restrictToWindowEdges } from '@dnd-kit/modifiers';
import { Deferred, Head, router, useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { AgendaList } from '@/components/posts/calendar/agenda-list';
import { CalendarHeader } from '@/components/posts/calendar/calendar-header';
import {
    MonthGrid,
    computeMonthDrop,
} from '@/components/posts/calendar/month-grid';
import type { WeekDropHint } from '@/components/posts/calendar/week-grid';
import {
    WeekGrid,
    computeWeekDrop,
} from '@/components/posts/calendar/week-grid';
import type { PostRowData } from '@/components/posts/post-row';
import { CalendarSkeleton } from '@/components/skeletons/calendar-skeleton';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { dayjs, parseYm, ymKey } from '@/lib/datetime/dayjs';
import type { Dayjs } from '@/lib/datetime/dayjs';
import { dashboard } from '@/routes';
import { month as calendarMonth } from '@/routes/calendar';
import { schedule } from '@/routes/posts';

type Props = {
    yyyymm: string;
    view: 'month' | 'week';
    posts?: PostRowData[];
};

export default function CalendarIndex({ yyyymm, view, posts }: Props) {
    const tz = useSchedulingTimezone();
    const anchor = parseYm(yyyymm) ?? dayjs().tz(tz).startOf('month');

    // Live drop preview for week-view dragging (target column + snapped time).
    const [dropHint, setDropHint] = useState<WeekDropHint | null>(null);

    // Week view anchors on a specific day (the `start` query param), defaulting
    // to today — not the month's 1st. This makes "Week" open the *current* week
    // with a live now-line and solid (non-dimmed) cells, so the hour grid lines
    // read crisply instead of washing out behind all-past, translucent cells.
    const startParam = new URL(
        usePage().url,
        'http://localhost',
    ).searchParams.get('start');
    const weekAnchor =
        view === 'week'
            ? startParam
                ? dayjs.tz(startParam, 'YYYY-MM-DD', tz)
                : dayjs().tz(tz)
            : anchor;
    const displayAnchor = view === 'week' ? weekAnchor : anchor;

    const label =
        view === 'month'
            ? anchor.format('MMMM YYYY')
            : weekAnchor.format('MMM D, YYYY');

    const http = useHttp<Record<string, never>, Record<string, never>>({});

    // A small activation distance lets a plain click through to the chip's
    // open-post handler; only a >4px drag starts a reschedule.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor),
    );

    // Week nav keeps yyyymm synced to the week's month so the server's
    // month-window post fetch always covers the visible week.
    function goToWeek(weekStart: Dayjs) {
        router.visit(calendarMonth({ yyyymm: ymKey(weekStart) }).url, {
            data: { view: 'week', start: weekStart.format('YYYY-MM-DD') },
        });
    }

    function goToMonth(monthAnchor: Dayjs) {
        router.visit(calendarMonth({ yyyymm: ymKey(monthAnchor) }).url, {
            data: { view: 'month' },
        });
    }

    function onPrev() {
        if (view === 'month') {
            goToMonth(anchor.subtract(1, 'month'));
        } else {
            goToWeek(weekAnchor.subtract(7, 'day'));
        }
    }

    function onNext() {
        if (view === 'month') {
            goToMonth(anchor.add(1, 'month'));
        } else {
            goToWeek(weekAnchor.add(7, 'day'));
        }
    }

    function onToday() {
        const today = dayjs().tz(tz);
        if (view === 'month') {
            goToMonth(today);
        } else {
            goToWeek(today);
        }
    }

    function onSetView(v: 'month' | 'week') {
        if (v === 'week') {
            goToWeek(dayjs().tz(tz));
        } else {
            goToMonth(anchor);
        }
    }

    function onSelectDate(d: Dayjs) {
        if (view === 'week') {
            goToWeek(d);
        } else {
            goToMonth(d);
        }
    }

    // Clicking an empty slot opens the composer pre-set to that schedule time.
    // Month cells default to 09:00; week cells use the clicked hour. Both resolve
    // the wall-clock time in the user's tz (same math as a drag-reschedule).
    function openComposerAt(day: Dayjs, hour: number) {
        const scheduleAt = computeWeekDrop(day.format('YYYY-MM-DD'), hour, tz);
        router.visit(dashboard().url, { data: { schedule_at: scheduleAt } });
    }

    function onEmptyDay(day: Dayjs) {
        openComposerAt(day, 9);
    }

    function onEmptyHour(day: Dayjs, hour: number) {
        openComposerAt(day, hour);
    }

    // Week view: derive a 15-minute offset within the dropped-over hour cell from
    // the cursor's vertical position, so drops aren't locked to whole hours.
    // Uses the pointer (activator + delta) rather than the chip rect, so the time
    // tracks where the user is actually pointing.
    function weekDropOffset(e: DragEndEvent | DragMoveEvent): {
        hourDelta: number;
        minute: number;
    } {
        const overRect = e.over?.rect;
        const activator = e.activatorEvent as { clientY?: number } | null;
        if (
            !overRect ||
            overRect.height <= 0 ||
            typeof activator?.clientY !== 'number'
        ) {
            return { hourDelta: 0, minute: 0 };
        }
        const pointerY = activator.clientY + e.delta.y;
        const frac = Math.min(
            Math.max((pointerY - overRect.top) / overRect.height, 0),
            1,
        );
        const snapped = Math.round((frac * 60) / 15) * 15; // 0 | 15 | 30 | 45 | 60
        return snapped >= 60
            ? { hourDelta: 1, minute: 0 }
            : { hourDelta: 0, minute: snapped };
    }

    // Track the live drop target while dragging in week view so the grid can
    // render a preview line + time at exactly where the post will land.
    function onDragMove(e: DragMoveEvent) {
        if (view !== 'week') {
            return;
        }
        const over = e.over?.data.current as
            | { day?: string; hour?: number }
            | undefined;
        if (!over?.day) {
            setDropHint(null);
            return;
        }
        const { hourDelta, minute } = weekDropOffset(e);
        setDropHint({
            day: over.day,
            hour: (over.hour ?? 0) + hourDelta,
            minute,
        });
    }

    function onDragCancel() {
        setDropHint(null);
    }

    function onDragEnd(e: DragEndEvent) {
        setDropHint(null);
        const active = e.active.data.current as
            | { scheduledAt?: string | null }
            | undefined;
        const over = e.over?.data.current as
            | { day?: string; hour?: number }
            | undefined;
        if (!active?.scheduledAt || !over?.day) {
            return;
        }
        let nextIso: string;
        if (view === 'month') {
            nextIso = computeMonthDrop(active.scheduledAt, over.day, tz);
        } else {
            const { hourDelta, minute } = weekDropOffset(e);
            nextIso = computeWeekDrop(
                over.day,
                (over.hour ?? 0) + hourDelta,
                tz,
                minute,
            );
        }
        if (nextIso === active.scheduledAt) {
            return;
        }
        const postId = String(e.active.id).replace(/^post-/, '');
        http.transform(() => ({ scheduled_at: nextIso }));
        void http
            .put(schedule({ post: postId }).url, {
                onNetworkError: () => undefined,
            })
            .then(() => router.reload({ only: ['posts'] }));
    }

    return (
        <>
            <Head title="Calendar" />

            <div className="mx-auto w-full max-w-6xl px-4 pt-6 pb-16 sm:px-6">
                <CalendarHeader
                    label={label}
                    view={view}
                    anchor={displayAnchor}
                    onPrev={onPrev}
                    onNext={onNext}
                    onToday={onToday}
                    onSetView={onSetView}
                    onSelectDate={onSelectDate}
                />

                <DndContext
                    sensors={sensors}
                    collisionDetection={pointerWithin}
                    modifiers={[restrictToWindowEdges]}
                    onDragMove={onDragMove}
                    onDragEnd={onDragEnd}
                    onDragCancel={onDragCancel}
                >
                    <Deferred data="posts" fallback={<CalendarSkeleton />}>
                        {/* Desktop: the dense month/week grids with drag-to-reschedule. */}
                        <div className="hidden sm:block">
                            {view === 'month' ? (
                                <MonthGrid
                                    anchor={anchor}
                                    posts={posts ?? []}
                                    onEmptyDayClick={onEmptyDay}
                                />
                            ) : (
                                <WeekGrid
                                    anchor={weekAnchor}
                                    posts={posts ?? []}
                                    onEmptyHourClick={onEmptyHour}
                                    dropHint={dropHint}
                                />
                            )}
                        </div>

                        {/* Mobile: a tappable agenda over the same date window. */}
                        <div className="sm:hidden">
                            <AgendaList
                                anchor={view === 'month' ? anchor : weekAnchor}
                                view={view}
                                posts={posts ?? []}
                                onEmptyDayClick={onEmptyDay}
                            />
                        </div>
                    </Deferred>
                </DndContext>
            </div>
        </>
    );
}

CalendarIndex.layout = {
    breadcrumbs: [
        {
            title: 'Calendar',
            href: '/calendar',
        },
    ],
};

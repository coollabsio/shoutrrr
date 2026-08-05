import { CalendarClock } from '@/components/ui/icons';
import { type Dayjs } from '@/lib/datetime/dayjs';
import {
    DISPLAY_DAYS,
    type Slot,
    timesForDay,
} from '@/lib/queue/queue-schedule';
import { cn } from '@/lib/utils';

/** Soonest future slot occurrence from `now`, scanning the next week. */
function nextOccurrence(slots: Slot[], now: Dayjs): Dayjs | null {
    let best: Dayjs | null = null;
    for (const slot of slots) {
        for (let offset = 0; offset <= 7; offset += 1) {
            const day = now.add(offset, 'day');
            if (day.day() !== slot.weekday) {
                continue;
            }
            const candidate = day
                .hour(slot.hour)
                .minute(slot.minute)
                .second(0)
                .millisecond(0);
            if (
                candidate.isAfter(now) &&
                (best === null || candidate.isBefore(best))
            ) {
                best = candidate;
            }
        }
    }

    return best;
}

function nextLabel(next: Dayjs | null, now: Dayjs): string {
    if (!next) {
        return '—';
    }
    const days = next.startOf('day').diff(now.startOf('day'), 'day');
    const time = next.format('h:mm A');
    if (days === 0) {
        return `Today · ${time}`;
    }
    if (days === 1) {
        return `Tomorrow · ${time}`;
    }

    return next.format('ddd · h:mm A');
}

/**
 * Fill height (as a %) for a day's track in the cadence rhythm strip, scaled to
 * the busiest day. A day with any slots keeps a visible floor so single-slot
 * days still read as filled.
 */
function barHeightPct(count: number, max: number): number {
    if (count === 0 || max <= 0) {
        return 0;
    }

    return Math.round(Math.max(0.18, count / max) * 100);
}

type Props = {
    slots: Slot[];
    now: Dayjs;
    todayWeekday: number;
};

/** Weekly cadence summary card: rhythm bar chart, post count headline, and next-post footer. */
export function CadenceOverview({ slots, now, todayWeekday }: Props) {
    const activeDays = new Set(slots.map((s) => s.weekday)).size;
    const next = slots.length > 0 ? nextOccurrence(slots, now) : null;
    const busiest = DISPLAY_DAYS.reduce(
        (m, d) => Math.max(m, timesForDay(slots, d.weekday).length),
        0,
    );

    return (
        <section className="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between sm:gap-10">
                {/* Headline — the cadence stated plainly. */}
                {slots.length === 0 ? (
                    <div className="min-w-0">
                        <p className="text-[20px] leading-tight font-semibold tracking-tight sm:text-[22px]">
                            No posting times yet
                        </p>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                            Add times below to build your weekly queue.
                        </p>
                    </div>
                ) : (
                    <div className="min-w-0">
                        <p className="text-[20px] leading-tight font-semibold tracking-tight sm:text-[22px]">
                            <span className="tabular-nums">{slots.length}</span>{' '}
                            {slots.length === 1 ? 'post' : 'posts'} a week
                        </p>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                            across{' '}
                            <span className="font-medium text-foreground tabular-nums">
                                {activeDays}
                            </span>{' '}
                            active {activeDays === 1 ? 'day' : 'days'}
                        </p>
                    </div>
                )}

                {/* Rhythm — fills rising in day tracks, today accented. */}
                <div className="flex w-full shrink-0 items-end gap-2 sm:w-72">
                    {DISPLAY_DAYS.map(({ weekday, label }) => {
                        const count = timesForDay(slots, weekday).length;
                        const isToday = weekday === todayWeekday;
                        const pct = barHeightPct(count, busiest);

                        return (
                            <div
                                key={weekday}
                                className="flex flex-1 flex-col items-center gap-2"
                            >
                                <div className="flex h-14 w-2.5 items-end overflow-hidden rounded-full bg-muted">
                                    <div
                                        className={cn(
                                            'w-full rounded-full transition-all',
                                            isToday
                                                ? 'bg-primary'
                                                : 'bg-primary/70',
                                        )}
                                        style={{ height: `${pct}%` }}
                                    />
                                </div>
                                <span
                                    className={cn(
                                        'text-[11px] tabular-nums',
                                        isToday
                                            ? 'font-semibold text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {label[0]}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Next post — a quiet footer line. */}
            {slots.length > 0 && (
                <div className="mt-5 flex items-center gap-2 border-t border-border pt-4 text-[13px]">
                    <CalendarClock
                        className="size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    <span className="text-muted-foreground">Next post</span>
                    <span className="ml-auto truncate font-medium tabular-nums">
                        {nextLabel(next, now)}
                    </span>
                </div>
            )}
        </section>
    );
}

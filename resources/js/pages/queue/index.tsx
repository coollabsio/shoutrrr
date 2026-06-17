import { Deferred, Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Copy, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import { QueueSkeleton } from '@/components/skeletons/queue-skeleton';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dayjs, type Dayjs } from '@/lib/datetime/dayjs';
import {
    addSlot,
    copyMondayToWeekdays,
    DISPLAY_DAYS,
    formatTime,
    mergeSlots,
    normalizeSlots,
    PRESETS,
    removeSlot,
    type Slot,
    slotsEqual,
    timesForDay,
} from '@/lib/queue/queue-schedule';
import { cn } from '@/lib/utils';

type Props = {
    timezone: string;
    slots?: Slot[];
    canManage: boolean;
};

export default function QueueIndex({ timezone, slots, canManage }: Props) {
    return (
        <>
            <Head title="Queue" />

            <div className="mx-auto w-full max-w-6xl space-y-5 px-4 pt-6 pb-16 sm:px-6">
                {/* Header — slots-independent, paints immediately */}
                <div className="flex flex-col gap-1">
                    <h1 className="text-[22px] leading-tight font-semibold tracking-tight">
                        Posting queue
                    </h1>
                    <p className="text-[13px] text-muted-foreground">
                        Queued posts go out at these times each week, in{' '}
                        <span className="font-medium text-foreground">
                            {timezone}
                        </span>{' '}
                        ·{' '}
                        <Link
                            href={
                                WorkspaceSettingsController.showOverview().url
                            }
                            className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                        >
                            change
                        </Link>
                    </p>
                </div>

                <Deferred data="slots" fallback={<QueueSkeleton />}>
                    <ScheduleEditor
                        key={normalizeSlots(slots ?? [])
                            .map((s) => `${s.weekday}:${s.hour}:${s.minute}`)
                            .join(',')}
                        initialSlots={normalizeSlots(slots ?? [])}
                        timezone={timezone}
                        canManage={canManage}
                    />
                </Deferred>
            </div>
        </>
    );
}

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

function ScheduleEditor({
    initialSlots,
    timezone,
    canManage,
}: {
    initialSlots: Slot[];
    timezone: string;
    canManage: boolean;
}) {
    const [slots, setSlots] = useState<Slot[]>(initialSlots);
    const [saving, setSaving] = useState(false);

    const dirty = !slotsEqual(slots, initialSlots);
    const activeDays = new Set(slots.map((s) => s.weekday)).size;

    const now = dayjs().tz(timezone);
    const todayWeekday = now.day();
    const next = slots.length > 0 ? nextOccurrence(slots, now) : null;
    const busiest = DISPLAY_DAYS.reduce(
        (m, d) => Math.max(m, timesForDay(slots, d.weekday).length),
        0,
    );

    function onSave() {
        setSaving(true);
        router.put(
            PostingScheduleController.update().url,
            { slots: normalizeSlots(slots) },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Queue saved.'),
                onError: () => toast.error('Could not save the queue.'),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="space-y-5">
            {/* Cadence overview — the week's rhythm at a glance + next post */}
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
                                <span className="tabular-nums">
                                    {slots.length}
                                </span>{' '}
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

            {canManage && (
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <span className="text-[12px] font-medium text-muted-foreground sm:mr-1">
                        Quick add
                    </span>
                    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                        {PRESETS.map((preset) => (
                            <Button
                                key={preset.label}
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-9 w-full justify-center rounded-lg text-[12px] sm:h-7 sm:w-auto sm:rounded-full"
                                onClick={() =>
                                    setSlots((s) => mergeSlots(s, preset.slots))
                                }
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                    {timesForDay(slots, 1).length > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-9 w-full justify-center gap-1.5 text-[12px] text-muted-foreground sm:h-7 sm:w-auto"
                            onClick={() =>
                                setSlots((s) => copyMondayToWeekdays(s))
                            }
                        >
                            <Copy className="size-3.5" aria-hidden />
                            Copy Monday → weekdays
                        </Button>
                    )}
                </div>
            )}

            {/* Week board */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                {DISPLAY_DAYS.map(({ weekday, label }) => {
                    const times = timesForDay(slots, weekday);
                    const isToday = weekday === todayWeekday;

                    return (
                        <div
                            key={weekday}
                            className={cn(
                                'flex flex-col gap-2 rounded-xl border bg-card p-3 transition-colors',
                                times.length === 0
                                    ? 'border-dashed border-border'
                                    : 'border-border',
                                isToday && 'ring-1 ring-primary/40',
                            )}
                        >
                            <div className="flex items-center justify-between">
                                <span
                                    className={cn(
                                        'text-[12.5px] font-semibold',
                                        isToday
                                            ? 'text-primary'
                                            : 'text-foreground',
                                    )}
                                >
                                    {label}
                                </span>
                                {times.length > 0 && (
                                    <span className="text-[11px] text-muted-foreground tabular-nums">
                                        {times.length}
                                    </span>
                                )}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                {times.length === 0 && (
                                    <span className="py-1 text-[12px] text-muted-foreground/70">
                                        No times
                                    </span>
                                )}
                                {times.map(({ hour, minute }) => (
                                    <span
                                        key={`${hour}:${minute}`}
                                        className="group/slot inline-flex items-center justify-between rounded-md bg-primary/10 py-1 pr-1 pl-2.5 text-[12.5px] font-medium text-foreground tabular-nums"
                                    >
                                        {formatTime(hour, minute)}
                                        {canManage && (
                                            <button
                                                type="button"
                                                aria-label={`Remove ${label} ${formatTime(hour, minute)}`}
                                                onClick={() =>
                                                    setSlots((s) =>
                                                        removeSlot(
                                                            s,
                                                            weekday,
                                                            hour,
                                                            minute,
                                                        ),
                                                    )
                                                }
                                                className="grid size-5 place-items-center rounded text-muted-foreground transition-colors hover:bg-primary/20 hover:text-foreground"
                                            >
                                                ×
                                            </button>
                                        )}
                                    </span>
                                ))}
                                {canManage && (
                                    <AddTime
                                        onAdd={(hour, minute) =>
                                            setSlots((s) =>
                                                addSlot(
                                                    s,
                                                    weekday,
                                                    hour,
                                                    minute,
                                                ),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Sticky save bar — only when there are unsaved edits */}
            {canManage && dirty && (
                <div className="sticky bottom-4 z-10 flex items-center justify-between gap-2 rounded-xl border border-border bg-card/95 px-3 py-2.5 shadow-lg backdrop-blur sm:gap-3 sm:px-4">
                    <span className="min-w-0 truncate text-[12.5px] text-muted-foreground">
                        Unsaved changes
                    </span>
                    <div className="flex shrink-0 items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-9 text-[12.5px] sm:h-8"
                            disabled={saving}
                            onClick={() => setSlots(initialSlots)}
                        >
                            Discard
                        </Button>
                        <Button
                            size="sm"
                            className="h-9 px-4 text-[12.5px] sm:h-8"
                            disabled={saving}
                            onClick={onSave}
                        >
                            {saving ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

const MINUTES = Array.from({ length: 60 }, (_, m) => m);

function AddTime({ onAdd }: { onAdd: (hour: number, minute: number) => void }) {
    const [open, setOpen] = useState(false);
    const [hour12, setHour12] = useState(9);
    const [minute, setMinute] = useState(0);
    const [meridiem, setMeridiem] = useState<'AM' | 'PM'>('AM');

    function to24(): number {
        if (meridiem === 'AM') {
            return hour12 === 12 ? 0 : hour12;
        }

        return hour12 === 12 ? 12 : hour12 + 12;
    }

    function add() {
        onAdd(to24(), minute);
        setOpen(false);
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center justify-center gap-1 rounded-md border border-dashed border-border py-1 text-[12px] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
                >
                    <Plus className="size-3.5" />
                    Add time
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto p-3">
                <div className="flex items-center gap-1.5">
                    <Select
                        value={String(hour12)}
                        onValueChange={(v) => setHour12(Number(v))}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 w-14 font-mono text-[12px] tabular-nums"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Array.from({ length: 12 }, (_, i) => i + 1).map(
                                (h) => (
                                    <SelectItem key={h} value={String(h)}>
                                        {String(h).padStart(2, '0')}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <span className="text-muted-foreground/60">:</span>
                    <Select
                        value={String(minute)}
                        onValueChange={(v) => setMinute(Number(v))}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 w-14 font-mono text-[12px] tabular-nums"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="max-h-60">
                            {MINUTES.map((m) => (
                                <SelectItem key={m} value={String(m)}>
                                    {String(m).padStart(2, '0')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="ml-1 inline-flex h-7 overflow-hidden rounded-md border border-border">
                        {(['AM', 'PM'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                aria-pressed={m === meridiem}
                                onClick={() => setMeridiem(m)}
                                className={
                                    m === meridiem
                                        ? 'inline-flex w-8 items-center justify-center bg-foreground text-[11px] font-medium text-background'
                                        : 'inline-flex w-8 items-center justify-center text-[11px] font-medium text-muted-foreground hover:bg-muted'
                                }
                            >
                                {m}
                            </button>
                        ))}
                    </div>
                    <Button
                        size="sm"
                        className="ml-1 h-7 text-[12px]"
                        onClick={add}
                    >
                        Add
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

QueueIndex.layout = {
    breadcrumbs: [
        {
            title: 'Queue',
            href: PostingScheduleController.show().url,
        },
    ],
};

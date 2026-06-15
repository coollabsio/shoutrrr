import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import Heading from '@/components/heading';
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
} from './queue-schedule';

type Props = {
    timezone: string;
    slots: Slot[];
    canManage: boolean;
};

export default function QueueIndex({ timezone, slots, canManage }: Props) {
    return (
        <>
            <Head title="Queue" />

            <div className="px-4 py-6">
                <Heading
                    title="Queue"
                    description="Posts you add to the queue go out at these times each week."
                />

                <ScheduleEditor
                    key={normalizeSlots(slots)
                        .map((s) => `${s.weekday}:${s.hour}:${s.minute}`)
                        .join(',')}
                    initialSlots={normalizeSlots(slots)}
                    timezone={timezone}
                    canManage={canManage}
                />
            </div>
        </>
    );
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
        <div className="mt-6 max-w-2xl space-y-6">
            <p className="text-[12px] text-muted-foreground">
                Times shown in{' '}
                <span className="font-medium text-foreground">{timezone}</span>{' '}
                ·{' '}
                <Link
                    href={WorkspaceSettingsController.showOverview().url}
                    className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                >
                    Change in workspace settings
                </Link>
            </p>

            {canManage && (
                <div className="space-y-2">
                    <span className="text-[12px] font-medium text-muted-foreground">
                        Quick add
                    </span>
                    <div className="flex flex-wrap gap-2">
                        {PRESETS.map((preset) => (
                            <Button
                                key={preset.label}
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-7 text-[12px]"
                                onClick={() =>
                                    setSlots((s) => mergeSlots(s, preset.slots))
                                }
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                </div>
            )}

            <div className="divide-y divide-border rounded-md border border-border">
                {DISPLAY_DAYS.map(({ weekday, label }) => {
                    const times = timesForDay(slots, weekday);

                    return (
                        <div
                            key={weekday}
                            className="flex items-center gap-3 px-3 py-2.5"
                        >
                            <span className="w-9 shrink-0 text-[12.5px] font-medium text-muted-foreground">
                                {label}
                            </span>
                            <div className="flex flex-1 flex-wrap items-center gap-1.5">
                                {times.length === 0 && (
                                    <span className="text-[12px] text-muted-foreground/70">
                                        No times
                                    </span>
                                )}
                                {times.map(({ hour, minute }) => (
                                    <span
                                        key={`${hour}:${minute}`}
                                        className="inline-flex h-6 items-center gap-1 rounded-full bg-primary/10 pr-1 pl-2 text-[12px] font-medium text-foreground"
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
                                                className="grid size-4 place-items-center rounded-full text-muted-foreground hover:bg-primary/20 hover:text-foreground"
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

            {canManage && timesForDay(slots, 1).length > 0 && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 text-[12px] text-muted-foreground"
                    onClick={() => setSlots((s) => copyMondayToWeekdays(s))}
                >
                    Copy Monday’s times to weekdays
                </Button>
            )}

            <div className="flex items-center justify-between">
                <span className="text-[12px] text-muted-foreground tabular-nums">
                    {slots.length} posts/week across {activeDays}{' '}
                    {activeDays === 1 ? 'day' : 'days'}
                </span>
                {canManage && (
                    <Button
                        size="sm"
                        className="h-8 rounded-md px-3 text-[12.5px]"
                        disabled={!dirty || saving}
                        onClick={onSave}
                    >
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                )}
            </div>
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
                    className="inline-flex h-6 items-center rounded-full border border-dashed border-border px-2 text-[12px] text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    + Add time
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

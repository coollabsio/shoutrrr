import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostingScheduleController from '@/actions/App/Http/Controllers/Settings/PostingScheduleController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

import {
    cellId,
    countDiff,
    hourLabel,
    setsEqual,
    setToSlots,
    slotsToSet,
    WEEKDAYS,
    type Slot,
} from './posting-schedule-grid';

type Props = {
    timezone: string;
    slots: Slot[];
    timezones: string[];
    canManage: boolean;
};

export default function PostingSchedule({
    timezone,
    slots,
    timezones,
    canManage,
}: Props) {
    const initialSet = slotsToSet(slots);

    return (
        <>
            <Head title="Posting schedule" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Posting schedule"
                    description="Define the weekly time slots used when you queue a post."
                />

                <ScheduleEditor
                    key={[...initialSet].toSorted((a, b) => a - b).join(',')}
                    initialTimezone={timezone}
                    initial={initialSet}
                    timezones={timezones}
                    canManage={canManage}
                />
            </div>
        </>
    );
}

function ScheduleEditor({
    initialTimezone,
    initial,
    timezones,
    canManage,
}: {
    initialTimezone: string;
    initial: Set<number>;
    timezones: string[];
    canManage: boolean;
}) {
    const [timezone, setTimezone] = useState(initialTimezone);
    const [selected, setSelected] = useState<Set<number>>(initial);
    const [mode, setMode] = useState<'on' | 'off' | null>(null);
    const [saving, setSaving] = useState(false);

    const dirty = !setsEqual(selected, initial) || timezone !== initialTimezone;
    const added = countDiff(selected, initial);
    const removed = countDiff(initial, selected);

    function paint(id: number, target: 'on' | 'off') {
        setSelected((s) => {
            const next = new Set(s);
            if (target === 'on') next.add(id);
            else next.delete(id);
            return next;
        });
    }

    function handlePointerDown(id: number) {
        if (!canManage) return;
        const target: 'on' | 'off' = selected.has(id) ? 'off' : 'on';
        setMode(target);
        paint(id, target);
    }

    function handlePointerEnter(e: React.PointerEvent, id: number) {
        if (!canManage || mode == null || e.buttons === 0) return;
        paint(id, mode);
    }

    function onCancel() {
        setSelected(initial);
        setTimezone(initialTimezone);
    }

    function onSave() {
        setSaving(true);
        router.put(
            PostingScheduleController.update().url,
            { timezone, slots: setToSlots(selected) },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Posting schedule saved.'),
                onError: () =>
                    toast.error('Could not save the posting schedule.'),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="space-y-4" onPointerUp={() => setMode(null)}>
            <div className="grid max-w-xs gap-2">
                <label className="text-sm font-medium" htmlFor="timezone">
                    Timezone
                </label>
                <Select
                    value={timezone}
                    onValueChange={setTimezone}
                    disabled={!canManage}
                >
                    <SelectTrigger id="timezone">
                        <SelectValue placeholder="Select a timezone" />
                    </SelectTrigger>
                    <SelectContent className="max-h-72">
                        {timezones.map((tz) => (
                            <SelectItem key={tz} value={tz}>
                                {tz}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div>
                <div className="grid grid-cols-[36px_repeat(7,_minmax(0,_1fr))] gap-px text-center text-[10.5px] font-medium tracking-wider text-muted-foreground uppercase">
                    <div />
                    {WEEKDAYS.map((d) => (
                        <div key={d} className="pb-1">
                            {d}
                        </div>
                    ))}
                </div>
                <div className="grid grid-cols-[36px_repeat(7,_minmax(0,_1fr))] gap-px">
                    {Array.from({ length: 24 }, (_, hour) => (
                        <Row
                            key={hour}
                            hour={hour}
                            selected={selected}
                            onPointerDown={handlePointerDown}
                            onPointerEnter={handlePointerEnter}
                        />
                    ))}
                </div>
            </div>

            {canManage && (
                <div className="flex items-center justify-between border-t border-border bg-muted/40 px-3 py-2">
                    <span className="text-[11.5px] text-muted-foreground tabular-nums">
                        {dirty ? (
                            <>
                                Changes:{' '}
                                <span className="text-foreground">
                                    +{added} / −{removed}
                                </span>
                            </>
                        ) : (
                            'No changes'
                        )}
                    </span>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-[12px]"
                            disabled={!dirty || saving}
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            className="h-8 rounded-md px-3 text-[12.5px]"
                            disabled={!dirty || saving}
                            onClick={onSave}
                        >
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

function Row({
    hour,
    selected,
    onPointerDown,
    onPointerEnter,
}: {
    hour: number;
    selected: Set<number>;
    onPointerDown: (id: number) => void;
    onPointerEnter: (e: React.PointerEvent, id: number) => void;
}) {
    const label = hourLabel(hour);
    return (
        <>
            <div className="pr-1 text-right text-[10.5px] text-muted-foreground tabular-nums">
                {label}
            </div>
            {WEEKDAYS.map((dayLabel, weekday) => {
                const id = cellId(weekday, hour);
                const on = selected.has(id);
                return (
                    <button
                        key={id}
                        type="button"
                        aria-pressed={on}
                        aria-label={`Toggle ${dayLabel} ${label}`}
                        onPointerDown={() => onPointerDown(id)}
                        onPointerEnter={(e) => onPointerEnter(e, id)}
                        className={cn(
                            'h-5 rounded-sm transition-colors',
                            on
                                ? 'bg-primary/15 hover:bg-primary/25'
                                : 'bg-muted/30 hover:bg-muted',
                        )}
                    />
                );
            })}
        </>
    );
}

PostingSchedule.layout = {
    breadcrumbs: [
        {
            title: 'Posting schedule',
            href: PostingScheduleController.show().url,
        },
    ],
};

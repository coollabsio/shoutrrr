import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import { dayjs } from '@/lib/datetime/dayjs';
import {
    addSlot,
    DISPLAY_DAYS,
    normalizeSlots,
    removeSlot,
    type Slot,
    slotsEqual,
} from '@/lib/queue/queue-schedule';

import { CadenceOverview } from './cadence-overview';
import { DayColumn } from './day-column';
import { QuickAddToolbar } from './quick-add-toolbar';
import { SaveBar } from './save-bar';

type Props = {
    initialSlots: Slot[];
    timezone: string;
    canManage: boolean;
};

/** Stateful schedule editor: owns slot state, persists via Inertia PUT, and composes child panels. */
export function ScheduleEditor({ initialSlots, timezone, canManage }: Props) {
    const [slots, setSlots] = useState<Slot[]>(initialSlots);
    const [saving, setSaving] = useState(false);

    const dirty = !slotsEqual(slots, initialSlots);

    const now = dayjs().tz(timezone);
    const todayWeekday = now.day();

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
            <CadenceOverview
                slots={slots}
                now={now}
                todayWeekday={todayWeekday}
            />

            {canManage && (
                <QuickAddToolbar slots={slots} onSlotsChange={setSlots} />
            )}

            {canManage && dirty && (
                <SaveBar
                    saving={saving}
                    onSave={onSave}
                    onDiscard={() => setSlots(initialSlots)}
                />
            )}

            {/* Week board */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                {DISPLAY_DAYS.map(({ weekday, label }) => (
                    <DayColumn
                        key={weekday}
                        weekday={weekday}
                        label={label}
                        slots={slots}
                        isToday={weekday === todayWeekday}
                        canManage={canManage}
                        onAdd={(hour, minute) =>
                            setSlots((s) => addSlot(s, weekday, hour, minute))
                        }
                        onRemove={(hour, minute) =>
                            setSlots((s) =>
                                removeSlot(s, weekday, hour, minute),
                            )
                        }
                    />
                ))}
            </div>

            {canManage && dirty && (
                <SaveBar
                    saving={saving}
                    onSave={onSave}
                    onDiscard={() => setSlots(initialSlots)}
                />
            )}
        </div>
    );
}

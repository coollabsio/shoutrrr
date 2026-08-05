import type { Dispatch, SetStateAction } from 'react';

import { Button } from '@/components/ui/button';
import { Copy } from '@/components/ui/icons';
import {
    copyMondayToWeekdays,
    mergeSlots,
    PRESETS,
    type Slot,
    timesForDay,
} from '@/lib/queue/queue-schedule';

type Props = {
    slots: Slot[];
    onSlotsChange: Dispatch<SetStateAction<Slot[]>>;
};

/** Preset quick-add buttons and the "copy Monday → weekdays" helper. */
export function QuickAddToolbar({ slots, onSlotsChange }: Props) {
    return (
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
                            onSlotsChange((current) =>
                                mergeSlots(current, preset.slots),
                            )
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
                        onSlotsChange((current) =>
                            copyMondayToWeekdays(current),
                        )
                    }
                >
                    <Copy className="size-3.5" aria-hidden />
                    Copy Monday → weekdays
                </Button>
            )}
        </div>
    );
}

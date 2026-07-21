import { Plus } from 'lucide-react';
import { useState } from 'react';

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

const HOUR_ITEMS = Array.from({ length: 12 }, (_, i) => i + 1).map((h) => ({
    value: String(h),
    label: String(h).padStart(2, '0'),
}));

const MINUTE_ITEMS = Array.from({ length: 60 }, (_, m) => m).map((m) => ({
    value: String(m),
    label: String(m).padStart(2, '0'),
}));

/** Inline time-picker popover that emits a 24-hour (hour, minute) pair on confirm. */
export function AddTimePopover({
    onAdd,
}: {
    onAdd: (hour: number, minute: number) => void;
}) {
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
            <PopoverTrigger
                render={
                    <button
                        type="button"
                        className="inline-flex items-center justify-center gap-1 rounded-md border border-dashed border-border py-1 text-[12px] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
                    />
                }
            >
                <Plus className="size-3.5" />
                Add time
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto p-3">
                <div className="flex items-center gap-1.5">
                    <Select
                        items={HOUR_ITEMS}
                        value={String(hour12)}
                        onValueChange={(v) => setHour12(Number(v))}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 w-16 px-2 font-mono text-[12px] tabular-nums"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {HOUR_ITEMS.map((item) => (
                                <SelectItem
                                    key={item.value}
                                    value={item.value}
                                    className="font-mono tabular-nums"
                                >
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <span className="text-muted-foreground/60">:</span>
                    <Select
                        items={MINUTE_ITEMS}
                        value={String(minute)}
                        onValueChange={(v) => setMinute(Number(v))}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 w-16 px-2 font-mono text-[12px] tabular-nums"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="max-h-60">
                            {MINUTE_ITEMS.map((item) => (
                                <SelectItem
                                    key={item.value}
                                    value={item.value}
                                    className="font-mono tabular-nums"
                                >
                                    {item.label}
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

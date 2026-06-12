import { format } from 'date-fns';
import { CalendarClock } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
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
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

type Props = {
    /** Initial value as an ISO datetime string, or null for the default. */
    value: string | null;
    onChange: (iso: string) => void;
};

const MINUTE_STEPS = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

/**
 * The time the picker shows when nothing is chosen yet: tomorrow 09:00 in the
 * browser's local timezone, as an ISO string. Exported so callers can seed
 * their state to the *same* value the picker displays — otherwise the UI shows
 * a time the request never sends (the backend rejects a null `scheduled_at`).
 */
export function defaultPickedAt(): string {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    d.setHours(9, 0, 0, 0);

    return d.toISOString();
}

function to12h(h24: number): { hour12: number; meridiem: 'AM' | 'PM' } {
    const meridiem = h24 < 12 ? 'AM' : 'PM';
    const hour12 = h24 % 12 === 0 ? 12 : h24 % 12;

    return { hour12, meridiem };
}

function to24h(hour12: number, meridiem: 'AM' | 'PM'): number {
    if (meridiem === 'AM') {
        return hour12 === 12 ? 0 : hour12;
    }

    return hour12 === 12 ? 12 : hour12 + 12;
}

export function PickTimePopover({ value, onChange }: Props) {
    const initial = value ? new Date(value) : new Date(defaultPickedAt());
    const [date, setDate] = useState<Date>(initial);
    const [hour, setHour] = useState<number>(initial.getHours());
    const [minute, setMinute] = useState<number>(
        initial.getMinutes() - (initial.getMinutes() % 5),
    );

    const display = new Date(date);
    display.setHours(hour, minute, 0, 0);
    const label = format(display, 'MMM d, h:mm a');
    const { hour12, meridiem } = to12h(hour);

    function commit(d: Date, h: number, m: number) {
        setDate(d);
        setHour(h);
        setMinute(m);
        const next = new Date(d);
        next.setHours(h, m, 0, 0);
        onChange(next.toISOString());
    }

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 gap-1.5 text-[12.5px] font-medium"
                >
                    <CalendarClock
                        className="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {label}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto gap-0 p-0">
                <Calendar
                    mode="single"
                    selected={date}
                    onSelect={(d) => d && commit(d, hour, minute)}
                    disabled={{
                        before: new Date(new Date().setHours(0, 0, 0, 0)),
                    }}
                />

                <Separator />

                <div className="flex items-center justify-between gap-2 px-3 py-2.5">
                    <span className="text-[11.5px] font-medium tracking-[-0.005em] text-muted-foreground">
                        Time
                    </span>
                    <div className="flex items-center gap-1">
                        <Select
                            value={String(hour12)}
                            onValueChange={(v) =>
                                commit(date, to24h(Number(v), meridiem), minute)
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="h-7 w-14 px-2 font-mono text-[12px] tabular-nums"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Array.from(
                                    { length: 12 },
                                    (_, i) => i + 1,
                                ).map((h) => (
                                    <SelectItem
                                        key={h}
                                        value={String(h)}
                                        className="font-mono tabular-nums"
                                    >
                                        {String(h).padStart(2, '0')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <span className="text-muted-foreground/60">:</span>
                        <Select
                            value={String(minute)}
                            onValueChange={(v) => commit(date, hour, Number(v))}
                        >
                            <SelectTrigger
                                size="sm"
                                className="h-7 w-14 px-2 font-mono text-[12px] tabular-nums"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MINUTE_STEPS.map((m) => (
                                    <SelectItem
                                        key={m}
                                        value={String(m)}
                                        className="font-mono tabular-nums"
                                    >
                                        {String(m).padStart(2, '0')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <div className="ml-1 inline-flex h-7 overflow-hidden rounded-md border border-border">
                            {(['AM', 'PM'] as const).map((m) => {
                                const active = m === meridiem;

                                return (
                                    <button
                                        key={m}
                                        type="button"
                                        onClick={() =>
                                            commit(
                                                date,
                                                to24h(hour12, m),
                                                minute,
                                            )
                                        }
                                        aria-pressed={active}
                                        className={cn(
                                            'inline-flex w-7 items-center justify-center text-[11px] font-medium tracking-[-0.005em] transition-colors',
                                            active
                                                ? 'bg-foreground text-background'
                                                : 'bg-transparent text-muted-foreground hover:bg-muted hover:text-foreground',
                                        )}
                                    >
                                        {m}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}

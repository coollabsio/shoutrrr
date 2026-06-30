import { Link } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useState } from 'react';

import { showOverview as workspaceSettings } from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
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
import { dayjs, userTz } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';

type Props = {
    /** Initial value as an ISO datetime string, or null for the default. */
    value: string | null;
    onChange: (iso: string) => void;
    /** The IANA timezone the picker operates in (workspace scheduling tz). */
    tz: string;
};

const MINUTE_STEPS = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

/**
 * The next clock hour in `tz`, as a UTC ISO string.
 *
 * Exported so callers can seed their state to the same value the picker
 * displays — otherwise the UI shows a time the request never sends (the
 * backend rejects a null `scheduled_at`).
 */
export function defaultPickedAt(tz: string): string {
    return dayjs()
        .tz(tz)
        .add(1, 'hour')
        .minute(0)
        .second(0)
        .millisecond(0)
        .utc()
        .format('YYYY-MM-DDTHH:mm:ss[Z]');
}

/**
 * Combine a calendar day + wall-clock hour/minute, interpreted in `tz`,
 * to a UTC ISO string.
 */
export function composePickedAt(
    dayIso: string, // 'YYYY-MM-DD' (the picked calendar date in tz)
    hour: number,
    minute: number,
    tz: string,
): string {
    return dayjs
        .tz(`${dayIso} ${hour}:${minute}`, 'YYYY-MM-DD H:m', tz)
        .second(0)
        .millisecond(0)
        .utc()
        .format('YYYY-MM-DDTHH:mm:ss[Z]');
}

/**
 * The wall-clock parts of an ISO instant, as seen in `tz`.
 */
export function partsInTz(
    iso: string,
    tz: string,
): { dayIso: string; hour: number; minute: number } {
    const d = dayjs(iso).tz(tz);
    return {
        dayIso: d.format('YYYY-MM-DD'),
        hour: d.hour(),
        minute: d.minute(),
    };
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

export function PickTimePopover({ value, onChange, tz }: Props) {
    const {
        dayIso: initialDayIso,
        hour: initialHour,
        minute: initialMinute,
    } = partsInTz(value ?? defaultPickedAt(tz), tz);

    // Display-only JS Date built from the day string — used only by react-day-picker.
    // The actual instant is always recomputed via composePickedAt so tz drift in the
    // Date's local interpretation never corrupts the result.
    const [dayIso, setDayIso] = useState<string>(initialDayIso);
    const [hour, setHour] = useState<number>(initialHour);
    const [minute, setMinute] = useState<number>(
        initialMinute - (initialMinute % 5),
    );

    const label = dayjs(value ?? defaultPickedAt(tz))
        .tz(tz)
        .format('MMM D, h:mm A');
    const { hour12, meridiem } = to12h(hour);
    const browserTz = userTz();
    const showBrowserTz = browserTz !== tz;

    function commit(iso: string, h: number, m: number) {
        setDayIso(iso);
        setHour(h);
        setMinute(m);
        onChange(composePickedAt(iso, h, m, tz));
    }

    // The selected day as a JS Date for react-day-picker display only.
    const selectedDate = new Date(`${dayIso}T00:00:00`);

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
                    selected={selectedDate}
                    onSelect={(d) => {
                        if (!d) return;
                        const picked = dayjs(d).format('YYYY-MM-DD');
                        commit(picked, hour, minute);
                    }}
                    disabled={{
                        // Disable days before "today" in the scheduling tz, so a
                        // past date can't be picked (the server also rejects it).
                        before: new Date(
                            `${dayjs().tz(tz).format('YYYY-MM-DD')}T00:00:00`,
                        ),
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
                                commit(
                                    dayIso,
                                    to24h(Number(v), meridiem),
                                    minute,
                                )
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
                            onValueChange={(v) =>
                                commit(dayIso, hour, Number(v))
                            }
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
                                                dayIso,
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

                <Separator />

                <p className="max-w-[300px] px-3 py-2.5 text-[11.5px] leading-4 text-muted-foreground">
                    Using workspace timezone{' '}
                    <span className="font-medium text-foreground">{tz}</span>.{' '}
                    {showBrowserTz && (
                        <>
                            <br />
                            Your timezone looks like{' '}
                            <span className="font-medium text-foreground">
                                {browserTz}
                            </span>
                            .{' '}
                        </>
                    )}
                    <br />
                    You can change it in{' '}
                    <Link
                        href={workspaceSettings().url}
                        className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                    >
                        workspace settings
                    </Link>
                    .
                </p>
            </PopoverContent>
        </Popover>
    );
}

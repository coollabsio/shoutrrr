import {
    Calendar as CalendarIcon,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { dayjs } from '@/lib/datetime/dayjs';
import type { Dayjs } from '@/lib/datetime/dayjs';

interface Props {
    label: string;
    view: 'month' | 'week';
    anchor: Dayjs;
    onPrev: () => void;
    onNext: () => void;
    onToday: () => void;
    onSetView: (v: 'month' | 'week') => void;
    onSelectDate: (d: Dayjs) => void;
}

export function CalendarHeader({
    label,
    view,
    anchor,
    onPrev,
    onNext,
    onToday,
    onSetView,
    onSelectDate,
}: Props) {
    const [open, setOpen] = useState(false);
    const tz = useSchedulingTimezone();
    const anchorJs = anchor.toDate();

    return (
        <div className="sticky top-0 z-10 flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-background/85 px-3 py-2 backdrop-blur-md">
            <div className="flex w-full items-center gap-1 sm:w-auto">
                <Button
                    variant="ghost"
                    size="sm"
                    className="size-8 p-0 sm:size-7"
                    onClick={onPrev}
                    aria-label={
                        view === 'month' ? 'Previous month' : 'Previous week'
                    }
                >
                    <ChevronLeft className="size-3.5" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    className="size-8 p-0 sm:size-7"
                    onClick={onNext}
                    aria-label={view === 'month' ? 'Next month' : 'Next week'}
                >
                    <ChevronRight className="size-3.5" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    className="order-last h-8 px-2.5 text-[12px] sm:order-none sm:h-7 sm:px-2"
                    onClick={onToday}
                >
                    Today
                </Button>

                <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger
                        render={
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label="Jump to date"
                                className="h-8 flex-1 justify-center gap-1.5 px-2 text-[15px] font-semibold tracking-tight tabular-nums hover:bg-muted/60 sm:ml-1 sm:h-7 sm:flex-none sm:justify-start"
                            />
                        }
                    >
                        <CalendarIcon
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        {label}
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-auto p-0">
                        <Calendar
                            mode="single"
                            defaultMonth={anchorJs}
                            selected={anchorJs}
                            onSelect={(d) => {
                                if (!d) return;
                                onSelectDate(dayjs(d).tz(tz));
                                setOpen(false);
                            }}
                        />
                    </PopoverContent>
                </Popover>
            </div>

            <div className="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                <span className="hidden text-[11px] text-muted-foreground sm:inline">
                    {tz}
                </span>
                <ToggleGroup
                    value={[view]}
                    size="sm"
                    variant="outline"
                    onValueChange={(value) => {
                        const v = value[0];
                        if (v === 'month' || v === 'week') onSetView(v);
                    }}
                    className="h-8 w-full sm:h-7 sm:w-auto"
                >
                    <ToggleGroupItem
                        value="month"
                        className="h-8 flex-1 px-2.5 text-[12.5px] font-medium sm:h-7 sm:flex-none sm:text-[11.5px]"
                    >
                        Month
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="week"
                        className="h-8 flex-1 px-2.5 text-[12.5px] font-medium sm:h-7 sm:flex-none sm:text-[11.5px]"
                    >
                        Week
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>
        </div>
    );
}

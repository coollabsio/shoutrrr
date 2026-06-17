import { Skeleton } from '@/components/ui/skeleton';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/**
 * Content-shaped placeholder for the deferred calendar. Matches the two real
 * views so streamed-in posts swap with minimal layout shift: the 7-column month
 * grid on `sm`+ (mirrors {@link MonthGrid}), and the date-rail agenda on mobile
 * (mirrors {@link AgendaList}). Used as the fallback for both month and week.
 */
export function CalendarSkeleton() {
    return (
        <>
            {/* Desktop: 7-column month grid with 96px cells + h-5 chips. */}
            <div className="hidden px-3 py-3 sm:block">
                <div className="mb-1 grid grid-cols-7 text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                    {WEEKDAYS.map((w) => (
                        <div key={w} className="px-2 pb-1.5 text-center">
                            {w}
                        </div>
                    ))}
                </div>
                <div className="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-border bg-border">
                    {Array.from({ length: 42 }).map((_, i) => (
                        <div key={i} className="min-h-[96px] bg-background p-1.5">
                            <div className="mb-1 flex h-[18px] items-center">
                                <Skeleton className="h-3 w-4 rounded-md" />
                            </div>
                            <div className="space-y-0.5">
                                {i % 3 === 0 && (
                                    <Skeleton className="h-5 w-full rounded-sm" />
                                )}
                                {i % 5 === 0 && (
                                    <Skeleton className="h-5 w-3/4 rounded-sm" />
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Mobile: date-rail agenda rows. */}
            <ol className="py-2 sm:hidden">
                {Array.from({ length: 8 }).map((_, i) => (
                    <li key={i} className="flex gap-3 py-1">
                        <div className="flex w-11 shrink-0 flex-col items-center gap-1 pt-1">
                            <Skeleton className="h-2.5 w-6 rounded" />
                            <Skeleton className="size-7 rounded-full" />
                        </div>
                        <div className="min-w-0 flex-1 space-y-1 border-l border-border/60 py-0.5 pl-3">
                            {i % 4 === 0 ? (
                                <Skeleton className="h-9 w-28 rounded-lg" />
                            ) : (
                                <>
                                    <Skeleton className="h-11 w-full rounded-lg" />
                                    {i % 3 === 0 && (
                                        <Skeleton className="h-11 w-4/5 rounded-lg" />
                                    )}
                                </>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
        </>
    );
}

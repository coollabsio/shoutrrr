import { Skeleton } from '@/components/ui/skeleton';

/**
 * Content-shaped placeholder for the deferred queue editor body. Mirrors the
 * `ScheduleEditor` cadence overview card and the 7-column week board (matching
 * its `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7` breakpoints,
 * `rounded-xl border p-3` day cells, and `h-[26px]` time pills) so the streamed
 * editor swaps in with zero layout shift. The slots-independent header lives on
 * the page above this fallback and paints immediately.
 */
export function QueueSkeleton() {
    return (
        <div className="space-y-5">
            {/* Cadence overview — headline + rhythm strip + next-post footer. */}
            <section className="rounded-xl border border-border bg-card p-5 sm:p-6">
                <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between sm:gap-10">
                    <div className="min-w-0">
                        <Skeleton className="h-6 w-40 rounded-md" />
                        <Skeleton className="mt-2 h-3.5 w-28 rounded-sm" />
                    </div>

                    <div className="flex w-full shrink-0 items-end gap-2 sm:w-72">
                        {[40, 70, 55, 30, 85, 20, 20].map((h, i) => (
                            <div
                                key={i}
                                className="flex flex-1 flex-col items-center gap-2"
                            >
                                <div className="flex h-14 w-2.5 items-end overflow-hidden rounded-full bg-muted">
                                    <Skeleton
                                        className="w-full rounded-full"
                                        style={{ height: `${h}%` }}
                                    />
                                </div>
                                <Skeleton className="h-3 w-3 rounded-sm" />
                            </div>
                        ))}
                    </div>
                </div>

                <div className="mt-5 flex items-center gap-2 border-t border-border pt-4">
                    <Skeleton className="size-4 rounded-sm" />
                    <Skeleton className="h-3.5 w-16 rounded-sm" />
                    <Skeleton className="ml-auto h-3.5 w-24 rounded-sm" />
                </div>
            </section>

            {/* Week board */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                {Array.from({ length: 7 }).map((_, day) => (
                    <div
                        key={day}
                        className="flex flex-col gap-2 rounded-xl border border-border bg-card p-3"
                    >
                        <div className="flex items-center justify-between">
                            <Skeleton className="h-3.5 w-8 rounded-sm" />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            {Array.from({ length: (day % 3) + 1 }).map(
                                (__, j) => (
                                    <Skeleton
                                        key={j}
                                        className="h-[26px] w-full rounded-md"
                                    />
                                ),
                            )}
                            <Skeleton className="h-[26px] w-full rounded-md opacity-60" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

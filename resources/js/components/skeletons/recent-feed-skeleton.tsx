import { Skeleton } from '@/components/ui/skeleton';

/**
 * Content-shaped placeholder for {@link RecentFeed}. Mirrors the real feed's
 * `mt-10` section, heading row, bordered card, and per-row grid so the deferred
 * posts swap in with zero layout shift.
 */
export function RecentFeedSkeleton() {
    return (
        <section className="mt-10">
            <div className="mb-3 flex items-center gap-3 px-0.5">
                <Skeleton className="h-3.5 w-24 rounded-md" />
                <Skeleton className="h-7 w-56 rounded-full" />
                <Skeleton className="ml-auto h-3 w-12 rounded-md" />
            </div>

            <div className="overflow-hidden rounded-xl border border-border">
                {Array.from({ length: 4 }).map((_, i) => (
                    <div
                        key={i}
                        className="grid grid-cols-[64px_1fr_auto] items-start gap-3 border-b border-border px-3 py-3 last:border-b-0 sm:grid-cols-[84px_1fr_auto] sm:gap-4"
                    >
                        {/* Time rail */}
                        <div className="space-y-1.5 pt-0.5">
                            <Skeleton className="h-3 w-10 rounded-md" />
                            <Skeleton className="h-2.5 w-12 rounded-md" />
                        </div>

                        {/* Text + meta */}
                        <div className="min-w-0 space-y-2">
                            <Skeleton className="h-3.5 w-3/4 rounded-md" />
                            <Skeleton className="h-3.5 w-1/2 rounded-md" />
                            <div className="flex items-center gap-1.5 pt-0.5">
                                <Skeleton className="size-[18px] rounded-[5px]" />
                                <Skeleton className="h-2.5 w-16 rounded-md" />
                            </div>
                        </div>

                        {/* Status badge */}
                        <Skeleton className="h-5 w-16 rounded-full" />
                    </div>
                ))}
            </div>
        </section>
    );
}

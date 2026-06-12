import { cn } from '@/lib/utils';

type CharCounterProps = {
    /**
     * For single-section posts this is the post's measured length; for
     * multi-section threads it is the total across the thread (per-section
     * counts show inline at each section-break marker).
     */
    count: number;
    /** Per-section character limit for the active platform. */
    limit: number;
    /** Total sections produced by the splitter. */
    sectionTotal: number;
    /** Severity for color treatment (warn → amber, over → destructive). */
    state: 'ok' | 'warn' | 'over';
};

export default function CharCounter({
    count,
    limit,
    sectionTotal,
    state,
}: CharCounterProps) {
    const isMulti = sectionTotal > 1;

    return (
        <div className="flex min-h-6 items-center justify-between gap-3 px-4 pb-3.5 text-[12px] text-muted-foreground sm:px-[26px]">
            <div className="flex min-w-0 items-center gap-2.5">
                {isMulti ? (
                    <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-muted px-2.5 py-0.5 text-[11.5px] font-medium text-foreground">
                        <span className="size-[5px] rounded-full bg-foreground" />
                        {sectionTotal}-post thread
                    </span>
                ) : (
                    <span className="truncate text-[11.5px] tracking-[-0.005em]">
                        Single post
                    </span>
                )}
            </div>
            <div
                className={cn(
                    'inline-flex shrink-0 items-baseline gap-0.5 font-mono text-[12px] tabular-nums',
                    state === 'warn' && 'text-amber-700 dark:text-amber-500',
                    state === 'over' && 'text-destructive',
                )}
            >
                <span className={state === 'ok' ? 'text-foreground' : ''}>
                    {count}
                </span>
                {!isMulti && (
                    <>
                        <span className="mx-px text-muted-foreground">/</span>
                        <span className="text-muted-foreground">{limit}</span>
                    </>
                )}
            </div>
        </div>
    );
}

import { DeltaChip } from '@/components/analytics/metric-delta';
import { Card, CardContent } from '@/components/ui/card';
import type { AnalyticsSummaryMetric } from '@/types/metrics';

type StatTileProps = {
    label: string;
    metric: AnalyticsSummaryMetric;
    /** Small context line under the number, e.g. "across 4 accounts". */
    caption: string;
    /** Screen-reader suffix for the delta, e.g. "vs previous period". */
    deltaLabel: string;
};

/**
 * A headline number with its change over the period — the at-a-glance answer to
 * "how am I doing" that the page opens with.
 */
export function StatTile({
    label,
    metric,
    caption,
    deltaLabel,
}: StatTileProps) {
    return (
        <Card size="sm" className="gap-0">
            <CardContent className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-muted-foreground">
                    {label}
                </span>
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span className="font-heading text-3xl leading-none font-semibold tracking-tight tabular-nums">
                        {metric.value.toLocaleString()}
                    </span>
                    <DeltaChip delta={metric.delta} label={deltaLabel} />
                </div>
                <span className="text-xs text-muted-foreground">{caption}</span>
            </CardContent>
        </Card>
    );
}

import { Head, Link } from '@inertiajs/react';
import { PauseCircle, TrendingUp } from 'lucide-react';
import { lazy, Suspense } from 'react';

import { StatTile } from '@/components/analytics/stat-tile';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Card, CardDescription, CardTitle } from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { dayjs } from '@/lib/datetime/dayjs';
import { disabledPlatformLabels } from '@/lib/platforms';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as analyticsRoute } from '@/routes/analytics';
import { show as postRoute } from '@/routes/posts';
import type {
    AnalyticsPageProps,
    AnalyticsComparisonRow,
} from '@/types/metrics';

// Lazily loaded so recharts is fetched only when there is series data to plot.
const FollowerChart = lazy(
    () => import('@/components/analytics/follower-chart'),
);

const RANGE_OPTIONS = [
    { days: 7, label: '7d' },
    { days: 14, label: '14d' },
    { days: 30, label: '30d' },
    { days: 90, label: '90d' },
];

// Trimming the response to just the range-dependent props keeps switching snappy.
const RANGE_RELOAD_PROPS = [
    'accounts',
    'posts',
    'summary',
    'comparison',
    'rangeDays',
];

function allDisabled(enabled: Record<string, boolean>) {
    return Object.values(enabled).every((value) => !value);
}

function DisabledMetricsNotice({
    title,
    disabledPlatforms,
    children,
}: {
    title: string;
    disabledPlatforms: string[];
    children: React.ReactNode;
}) {
    if (disabledPlatforms.length === 0) {
        return null;
    }

    return (
        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
            <div className="flex items-start gap-2">
                <PauseCircle className="mt-0.5 size-4 shrink-0" />
                <div>
                    <p className="font-medium">{title}</p>
                    <p className="mt-0.5">
                        {children}{' '}
                        <span className="font-medium">
                            {disabledPlatforms.join(', ')}
                        </span>
                        .
                    </p>
                </div>
            </div>
        </div>
    );
}

function AnalyticsPollingBanner({
    disabledAccountMetricPlatforms,
    disabledPostMetricPlatforms,
}: {
    disabledAccountMetricPlatforms: string[];
    disabledPostMetricPlatforms: string[];
}) {
    if (
        disabledAccountMetricPlatforms.length === 0 &&
        disabledPostMetricPlatforms.length === 0
    ) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-amber-500/20 bg-amber-500/10 px-5 py-4 text-sm text-amber-900 dark:text-amber-200">
            <div className="flex items-start gap-3">
                <PauseCircle className="mt-0.5 size-5 shrink-0" />
                <div>
                    <p className="font-semibold">
                        Some analytics are temporarily disabled
                    </p>
                    <div className="mt-1 space-y-1">
                        {disabledAccountMetricPlatforms.length > 0 && (
                            <p>
                                Account metrics are paused for{' '}
                                <span className="font-medium">
                                    {disabledAccountMetricPlatforms.join(', ')}
                                </span>
                                .
                            </p>
                        )}
                        {disabledPostMetricPlatforms.length > 0 && (
                            <p>
                                Post metrics are paused for{' '}
                                <span className="font-medium">
                                    {disabledPostMetricPlatforms.join(', ')}
                                </span>
                                .
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

type ComparisonItemProps = {
    row: AnalyticsComparisonRow;
    rank: number;
    isTop: boolean;
};

function ComparisonItem({ row, rank, isTop }: ComparisonItemProps) {
    return (
        <Link
            href={postRoute(row.id).url}
            className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
        >
            <span
                className={cn(
                    'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                    isTop
                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {rank}
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm leading-snug font-medium">
                    {row.title || 'Untitled post'}
                </p>
                <div className="mt-1 flex items-center gap-1.5">
                    {row.platforms.map((p) => (
                        <span key={p} className="text-muted-foreground">
                            <PlatformGlyph platform={p} size={11} />
                        </span>
                    ))}
                    <span className="text-xs text-muted-foreground">
                        {dayjs(row.published_at).format('MMM D')}
                    </span>
                </div>
            </div>
            <div className="shrink-0 text-right">
                <p className="text-sm font-semibold tabular-nums">
                    {row.engagement.toLocaleString()}
                </p>
                <p className="text-xs text-muted-foreground">engagement</p>
            </div>
        </Link>
    );
}

function FollowerChartSkeleton({ count }: { count: number }) {
    return (
        <div
            className={
                count > 1
                    ? 'grid grid-cols-1 gap-4 lg:grid-cols-2'
                    : 'grid grid-cols-1 gap-4'
            }
        >
            {Array.from({ length: Math.max(count, 1) }).map((_, i) => (
                <div
                    key={i}
                    className="h-[196px] w-full animate-pulse rounded-[min(var(--radius-4xl),24px)] bg-muted/50"
                />
            ))}
        </div>
    );
}

export default function AnalyticsIndex({
    accounts,
    posts,
    summary,
    comparison,
    polling,
    rangeDays,
}: AnalyticsPageProps) {
    const hasSeries = accounts.some((a) => a.series.length > 0);
    const disabledAccountMetricPlatforms = disabledPlatformLabels(
        polling.account_metrics_enabled,
    );
    const disabledPostMetricPlatforms = disabledPlatformLabels(
        polling.post_metrics_enabled,
    );
    const metricsDisabled =
        allDisabled(polling.account_metrics_enabled) &&
        allDisabled(polling.post_metrics_enabled);
    const rangeLabel =
        RANGE_OPTIONS.find((opt) => opt.days === rangeDays)?.label ??
        `${rangeDays}d`;

    return (
        <>
            <Head title="Analytics" />

            <div className="mx-auto w-full max-w-6xl space-y-6 px-4 pt-6 pb-16 sm:px-6">
                {/* Page header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-2xl leading-tight font-semibold tracking-tight">
                            Analytics
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Follower growth and post engagement across your
                            accounts.
                        </p>
                    </div>

                    {/* Range selector */}
                    <div className="flex items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-1">
                        {RANGE_OPTIONS.map((opt) => (
                            <Link
                                key={opt.days}
                                href={
                                    analyticsRoute({
                                        query: { days: opt.days },
                                    }).url
                                }
                                preserveScroll
                                preserveState
                                only={RANGE_RELOAD_PROPS}
                                className={cn(
                                    'rounded-md px-3 py-1 text-sm font-medium transition-all active:scale-[0.97]',
                                    rangeDays === opt.days
                                        ? 'bg-background text-foreground shadow-sm ring-1 ring-border/60'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {opt.label}
                            </Link>
                        ))}
                    </div>
                </div>

                {metricsDisabled && (
                    <Empty className="rounded-2xl border border-dashed">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <PauseCircle />
                            </EmptyMedia>
                            <EmptyTitle>
                                Metrics temporarily disabled
                            </EmptyTitle>
                            <EmptyDescription>
                                Account and post metric polling is paused by
                                your instance admin. Existing data remains
                                visible and new data will resume after polling
                                is enabled again.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                )}

                {!metricsDisabled && (
                    <>
                        {/* Headline numbers — the at-a-glance answer */}
                        {accounts.length > 0 && (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <StatTile
                                    label="Followers"
                                    metric={summary.followers}
                                    caption={`across ${summary.account_count} ${summary.account_count === 1 ? 'account' : 'accounts'}`}
                                    deltaLabel={`followers over the last ${rangeLabel}`}
                                />
                                <StatTile
                                    label="Engagement"
                                    metric={summary.engagement}
                                    caption="likes, comments & reposts this period"
                                    deltaLabel="vs previous period"
                                />
                                <StatTile
                                    label="Posts published"
                                    metric={summary.posts}
                                    caption="in the selected period"
                                    deltaLabel="vs previous period"
                                />
                            </div>
                        )}

                        <AnalyticsPollingBanner
                            disabledAccountMetricPlatforms={
                                disabledAccountMetricPlatforms
                            }
                            disabledPostMetricPlatforms={
                                disabledPostMetricPlatforms
                            }
                        />

                        {/* Follower growth — all accounts on one shared timeline */}
                        {accounts.length > 0 && (
                            <section className="space-y-3">
                                <div>
                                    <h2 className="font-heading text-base font-medium">
                                        Follower growth
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Each account on its own scale over the
                                        last {rangeLabel}
                                    </p>
                                </div>

                                <DisabledMetricsNotice
                                    title="Some account metrics are temporarily disabled"
                                    disabledPlatforms={
                                        disabledAccountMetricPlatforms
                                    }
                                >
                                    Follower snapshots are paused for
                                </DisabledMetricsNotice>

                                {allDisabled(
                                    polling.account_metrics_enabled,
                                ) ? (
                                    <Empty className="rounded-2xl border border-dashed py-10">
                                        <EmptyHeader>
                                            <EmptyMedia variant="icon">
                                                <PauseCircle />
                                            </EmptyMedia>
                                            <EmptyTitle className="text-base">
                                                Account metrics temporarily
                                                disabled
                                            </EmptyTitle>
                                            <EmptyDescription>
                                                Follower growth snapshots are
                                                paused by your instance admin.
                                            </EmptyDescription>
                                        </EmptyHeader>
                                    </Empty>
                                ) : !hasSeries ? (
                                    <Empty className="rounded-2xl border border-dashed py-10">
                                        <EmptyHeader>
                                            <EmptyMedia variant="icon">
                                                <TrendingUp />
                                            </EmptyMedia>
                                            <EmptyTitle className="text-base">
                                                Collecting your data
                                            </EmptyTitle>
                                            <EmptyDescription>
                                                First numbers appear after the
                                                next sync — follower snapshots
                                                refresh a few times a day.
                                            </EmptyDescription>
                                        </EmptyHeader>
                                    </Empty>
                                ) : (
                                    <Suspense
                                        fallback={
                                            <FollowerChartSkeleton
                                                count={accounts.length}
                                            />
                                        }
                                    >
                                        <FollowerChart
                                            accounts={accounts}
                                            posts={posts}
                                            accountMetricsEnabled={
                                                polling.account_metrics_enabled
                                            }
                                        />
                                    </Suspense>
                                )}
                            </section>
                        )}
                    </>
                )}

                {/* Post comparison */}
                {!metricsDisabled &&
                    (allDisabled(polling.post_metrics_enabled) ? (
                        <Empty className="rounded-2xl border border-dashed">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <PauseCircle />
                                </EmptyMedia>
                                <EmptyTitle>
                                    Post metrics temporarily disabled
                                </EmptyTitle>
                                <EmptyDescription>
                                    Post engagement polling is paused by your
                                    instance admin.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        (comparison.top.length > 0 ||
                            comparison.bottom.length > 0) && (
                            <div className="space-y-4">
                                <DisabledMetricsNotice
                                    title="Some post metrics are temporarily disabled"
                                    disabledPlatforms={
                                        disabledPostMetricPlatforms
                                    }
                                >
                                    Post engagement polling is paused for
                                </DisabledMetricsNotice>

                                {comparison.bottom.length === 0 ? (
                                    /* Fewer than 10 eligible posts — single ranked list */
                                    <Card className="gap-0 py-0">
                                        <div className="px-5 py-4">
                                            <CardTitle>
                                                Posts by engagement
                                            </CardTitle>
                                            <CardDescription className="mt-1">
                                                All posts ranked by engagement
                                                this period
                                            </CardDescription>
                                        </div>
                                        <div className="divide-y divide-border border-t border-border">
                                            {comparison.top.map((row, i) => (
                                                <ComparisonItem
                                                    key={row.id}
                                                    row={row}
                                                    rank={i + 1}
                                                    isTop={true}
                                                />
                                            ))}
                                        </div>
                                    </Card>
                                ) : (
                                    /* 10+ eligible posts — best vs needs-attention two-column layout */
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {comparison.top.length > 0 && (
                                            <Card className="gap-0 py-0">
                                                <div className="px-5 py-4">
                                                    <CardTitle>
                                                        Best performing
                                                    </CardTitle>
                                                    <CardDescription className="mt-1">
                                                        Highest engagement this
                                                        period
                                                    </CardDescription>
                                                </div>
                                                <div className="divide-y divide-border border-t border-border">
                                                    {comparison.top.map(
                                                        (row, i) => (
                                                            <ComparisonItem
                                                                key={row.id}
                                                                row={row}
                                                                rank={i + 1}
                                                                isTop={true}
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                            </Card>
                                        )}

                                        <Card className="gap-0 py-0">
                                            <div className="px-5 py-4">
                                                <CardTitle>
                                                    Needs attention
                                                </CardTitle>
                                                <CardDescription className="mt-1">
                                                    Lowest engagement this
                                                    period
                                                </CardDescription>
                                            </div>
                                            <div className="divide-y divide-border border-t border-border">
                                                {comparison.bottom.map(
                                                    (row, i) => (
                                                        <ComparisonItem
                                                            key={row.id}
                                                            row={row}
                                                            rank={i + 1}
                                                            isTop={false}
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        </Card>
                                    </div>
                                )}
                            </div>
                        )
                    ))}

                {/* No accounts at all */}
                {accounts.length === 0 && (
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <TrendingUp />
                            </EmptyMedia>
                            <EmptyTitle>No accounts connected</EmptyTitle>
                            <EmptyDescription>
                                Connect an account to start tracking follower
                                growth and post engagement.
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Link
                                href={dashboard().url}
                                className="inline-flex h-9 items-center justify-center rounded-md border border-border bg-background px-4 text-sm font-medium transition-all hover:bg-muted active:scale-[0.98]"
                            >
                                Go to Dashboard
                            </Link>
                        </EmptyContent>
                    </Empty>
                )}
            </div>
        </>
    );
}

AnalyticsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Analytics',
            href: analyticsRoute().url,
        },
    ],
};

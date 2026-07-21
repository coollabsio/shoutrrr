import { router } from '@inertiajs/react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';

import {
    accountChartColor,
    followerYDomain,
    formatFollowerTooltipDate,
} from '@/components/analytics/follower-chart-utils';
import { DeltaChip } from '@/components/analytics/metric-delta';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { dayjs } from '@/lib/datetime/dayjs';
import { show as postRoute } from '@/routes/posts';
import type {
    AnalyticsAccount,
    AnalyticsPageProps,
    AnalyticsPostMarker,
} from '@/types/metrics';

export {
    accountChartColor,
    followerYDomain,
    formatFollowerTooltipDate,
} from '@/components/analytics/follower-chart-utils';

type FollowerChartProps = {
    accounts: AnalyticsPageProps['accounts'];
    posts: AnalyticsPageProps['posts'];
    /** Per-platform account-metric polling switches. */
    accountMetricsEnabled: Record<string, boolean>;
};

type TrendPoint = { date: number; value: number };

function seriesPoints(account: AnalyticsAccount): TrendPoint[] {
    return account.series
        .filter((point) => point.followers != null)
        .map((point) => ({
            date: dayjs(point.at).valueOf(),
            value: point.followers,
        }));
}

/**
 * Follower growth as one auto-scaled area chart per account. Each account gets
 * its own y-axis so a +100 change on a 1,600-follower account and a +25 change
 * from zero both read as real movement, instead of being flattened onto one
 * shared axis. Lives in its own module so recharts is code-split into a lazily
 * loaded chunk.
 */
export default function FollowerChart({
    accounts,
    posts,
    accountMetricsEnabled,
}: FollowerChartProps) {
    // A shared time window across every panel keeps them comparable left-to-right.
    const allDates = accounts.flatMap((account) =>
        seriesPoints(account).map((point) => point.date),
    );
    const xDomain: [number, number] | undefined =
        allDates.length > 0
            ? [Math.min(...allDates), Math.max(...allDates)]
            : undefined;

    return (
        <div
            className={
                accounts.length > 1
                    ? 'grid grid-cols-1 gap-4 lg:grid-cols-2'
                    : 'grid grid-cols-1 gap-4'
            }
        >
            {accounts.map((account, index) => (
                <AccountTrendCard
                    key={account.id}
                    account={account}
                    color={accountChartColor(index)}
                    metricsEnabled={
                        accountMetricsEnabled[account.platform] ?? true
                    }
                    posts={posts.filter((post) =>
                        post.platforms.includes(account.platform),
                    )}
                    xDomain={xDomain}
                />
            ))}
        </div>
    );
}

type AccountTrendCardProps = {
    account: AnalyticsAccount;
    color: string;
    metricsEnabled: boolean;
    posts: AnalyticsPostMarker[];
    xDomain: [number, number] | undefined;
};

function AccountTrendCard({
    account,
    color,
    metricsEnabled,
    posts,
    xDomain,
}: AccountTrendCardProps) {
    const label = account.display_name ?? account.handle;
    const points = seriesPoints(account);
    const values = points.map((point) => point.value);
    const [yMin, yMax] = followerYDomain(values);
    const fillId = `follower-fill-${account.id}`;

    // Only mark posts that fall inside this panel's visible time window.
    const markers = posts.filter((post) => {
        if (!xDomain) {
            return false;
        }
        const at = new Date(post.published_at).getTime();
        return at >= xDomain[0] && at <= xDomain[1];
    });

    const chartConfig: ChartConfig = {
        value: { label, color },
    };

    return (
        <div className="flex flex-col gap-3 rounded-[min(var(--radius-4xl),24px)] bg-card p-4 shadow-sm ring-1 ring-foreground/5 dark:ring-foreground/10">
            <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-foreground">
                        <PlatformGlyph platform={account.platform} size={13} />
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{label}</p>
                        <p className="text-xs text-muted-foreground">
                            followers
                        </p>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <span className="font-heading text-xl leading-none font-semibold tracking-tight tabular-nums">
                        {account.latest_followers !== null
                            ? account.latest_followers.toLocaleString()
                            : '—'}
                    </span>
                    {metricsEnabled && (
                        <DeltaChip
                            delta={account.followers_delta}
                            label="followers this period"
                        />
                    )}
                </div>
            </div>

            {!metricsEnabled ? (
                <p className="flex h-[120px] items-center justify-center rounded-lg bg-muted/30 text-center text-xs text-muted-foreground">
                    Account metrics are paused for this platform.
                </p>
            ) : points.length < 2 ? (
                <p className="flex h-[120px] items-center justify-center rounded-lg bg-muted/30 text-center text-xs text-muted-foreground">
                    Not enough history yet — check back after the next sync.
                </p>
            ) : (
                <>
                    <ChartContainer
                        config={chartConfig}
                        className="h-[120px] w-full"
                        initialDimension={{ width: 400, height: 120 }}
                    >
                        <AreaChart
                            data={points}
                            margin={{ top: 4, right: 4, bottom: 0, left: 4 }}
                        >
                            <defs>
                                <linearGradient
                                    id={fillId}
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="5%"
                                        stopColor={color}
                                        stopOpacity={0.25}
                                    />
                                    <stop
                                        offset="95%"
                                        stopColor={color}
                                        stopOpacity={0}
                                    />
                                </linearGradient>
                            </defs>
                            <CartesianGrid
                                vertical={false}
                                className="stroke-border/50"
                            />
                            <XAxis
                                dataKey="date"
                                type="number"
                                scale="time"
                                domain={xDomain ?? ['dataMin', 'dataMax']}
                                hide
                            />
                            <YAxis domain={[yMin, yMax]} hide />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        labelFormatter={
                                            formatFollowerTooltipDate
                                        }
                                        indicator="dot"
                                    />
                                }
                            />

                            {/* Post publish markers — when this account posted. */}
                            {markers.map((post) => (
                                <ReferenceLine
                                    key={post.id}
                                    x={new Date(post.published_at).getTime()}
                                    stroke="var(--muted-foreground)"
                                    strokeDasharray="3 3"
                                    strokeOpacity={0.4}
                                    label={(props: {
                                        viewBox?: { x?: number; y?: number };
                                    }) => (
                                        <PostMarker
                                            x={props.viewBox?.x ?? 0}
                                            y={props.viewBox?.y ?? 0}
                                            post={post}
                                        />
                                    )}
                                />
                            ))}

                            <Area
                                dataKey="value"
                                type="monotone"
                                stroke={color}
                                strokeWidth={2}
                                fill={`url(#${fillId})`}
                                fillOpacity={1}
                                baseValue={yMin}
                                dot={false}
                                activeDot={{
                                    r: 4,
                                    strokeWidth: 2,
                                    className: 'stroke-background',
                                }}
                                connectNulls
                            />
                        </AreaChart>
                    </ChartContainer>
                    <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span className="tabular-nums">
                            {dayjs(points[0].date).format('MMM D')}
                        </span>
                        {markers.length > 0 && (
                            <span className="flex items-center gap-1.5">
                                <span
                                    aria-hidden="true"
                                    className="inline-block h-0 w-4 border-t border-dashed border-muted-foreground/60"
                                />
                                {markers.length}{' '}
                                {markers.length === 1 ? 'post' : 'posts'}
                            </span>
                        )}
                        <span className="tabular-nums">
                            {dayjs(points[points.length - 1].date).format(
                                'MMM D',
                            )}
                        </span>
                    </div>
                </>
            )}
        </div>
    );
}

type PostMarkerProps = {
    x: number;
    y: number;
    post: AnalyticsPostMarker;
};

function PostMarker({ x, y, post }: PostMarkerProps) {
    return (
        <g
            transform={`translate(${x}, ${y})`}
            className="cursor-pointer"
            onClick={() => router.visit(postRoute(post.id).url)}
        >
            <title>
                {`${post.title || 'Untitled post'} · ${dayjs(post.published_at).format('MMM D')}`}
            </title>
            {/* Larger transparent hit target for easy hover/click. */}
            <circle r={7} fill="transparent" />
            <circle r={2.5} className="fill-muted-foreground" />
        </g>
    );
}

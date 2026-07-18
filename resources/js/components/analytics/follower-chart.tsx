import {
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';

import {
    accountChartColor,
    buildFollowerChartData,
    formatFollowerTooltipDate,
} from '@/components/analytics/follower-chart-utils';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';
import type { AnalyticsPageProps } from '@/types/metrics';

export {
    accountChartColor,
    buildFollowerChartData,
    formatFollowerTooltipDate,
    nextHiddenAccountIds,
    type FollowerChartRow,
} from '@/components/analytics/follower-chart-utils';

type FollowerChartProps = {
    accounts: AnalyticsPageProps['accounts'];
    posts: AnalyticsPageProps['posts'];
    /** Account ids currently hidden from the graph. */
    hiddenAccountIds: ReadonlySet<string>;
    onToggleAccount: (accountId: string) => void;
};

/**
 * The follower-growth line chart. Lives in its own module so recharts (a heavy
 * dependency) is code-split into a lazily-loaded chunk and only fetched when
 * there is series data to plot.
 */
export default function FollowerChart({
    accounts,
    posts,
    hiddenAccountIds,
    onToggleAccount,
}: FollowerChartProps) {
    const chartData = buildFollowerChartData(accounts);
    const visibleAccounts = accounts.filter(
        (account) => !hiddenAccountIds.has(account.id),
    );

    const chartConfig: ChartConfig = Object.fromEntries(
        accounts.map((a, i) => [
            a.id,
            {
                label: a.display_name ?? a.handle,
                color: accountChartColor(i),
            },
        ]),
    );

    return (
        <div className="p-5">
            <ChartContainer
                config={chartConfig}
                className="h-[260px] w-full"
                initialDimension={{ width: 800, height: 260 }}
            >
                <LineChart
                    data={chartData}
                    margin={{ top: 4, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid
                        strokeDasharray="3 3"
                        className="stroke-border/50"
                        vertical={false}
                    />
                    <XAxis
                        dataKey="date"
                        type="number"
                        scale="time"
                        domain={['dataMin', 'dataMax']}
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        tick={{ fontSize: 11 }}
                        tickFormatter={(v: number) => dayjs(v).format('MMM D')}
                        interval="preserveStartEnd"
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        tick={{ fontSize: 11 }}
                        tickFormatter={(v: number) =>
                            v >= 1000 ? `${(v / 1000).toFixed(1)}k` : String(v)
                        }
                        width={40}
                    />
                    <ChartTooltip
                        shared
                        content={
                            <ChartTooltipContent
                                labelFormatter={formatFollowerTooltipDate}
                                indicator="dot"
                            />
                        }
                    />

                    {/* Post publish markers */}
                    {posts.map((post) => (
                        <ReferenceLine
                            key={post.id}
                            x={new Date(post.published_at).getTime()}
                            stroke="var(--primary)"
                            strokeDasharray="3 3"
                            strokeWidth={1.5}
                            opacity={0.6}
                            label={(props: {
                                viewBox?: { x?: number; y?: number };
                            }) => {
                                const x = (props.viewBox?.x ?? 0) + 3;
                                const y = (props.viewBox?.y ?? 0) + 12;
                                return (
                                    <text
                                        x={x}
                                        y={y}
                                        fontSize={10}
                                        fill="var(--primary)"
                                    >
                                        <title>
                                            {post.title || 'Untitled post'}
                                        </title>
                                        ↑
                                    </text>
                                );
                            }}
                        />
                    ))}

                    {visibleAccounts.map((account) => {
                        const colorIndex = accounts.findIndex(
                            (a) => a.id === account.id,
                        );

                        return (
                            <Line
                                key={account.id}
                                dataKey={account.id}
                                type="monotone"
                                stroke={accountChartColor(colorIndex)}
                                strokeWidth={2}
                                dot={false}
                                activeDot={{ r: 4, strokeWidth: 0 }}
                                connectNulls
                            />
                        );
                    })}
                </LineChart>
            </ChartContainer>

            {/* Legend — click to show/hide series */}
            {accounts.length > 1 && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {accounts.map((account, i) => {
                        const hidden = hiddenAccountIds.has(account.id);
                        const label = account.display_name ?? account.handle;

                        return (
                            <button
                                key={account.id}
                                type="button"
                                onClick={() => onToggleAccount(account.id)}
                                aria-pressed={!hidden}
                                aria-label={
                                    hidden
                                        ? `Show ${label} on graph`
                                        : `Hide ${label} from graph`
                                }
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-full border px-2 py-1 transition-opacity',
                                    hidden
                                        ? 'border-transparent opacity-40 hover:opacity-70'
                                        : 'border-border/60 bg-muted/40 opacity-100 hover:bg-muted/70',
                                )}
                            >
                                <span
                                    className="block h-2 w-4 rounded-full"
                                    style={{
                                        backgroundColor: accountChartColor(i),
                                    }}
                                />
                                <span className="flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <PlatformGlyph
                                        platform={account.platform}
                                        size={10}
                                    />
                                    {label}
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

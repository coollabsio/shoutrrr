import { Deferred, useHttp, usePage } from '@inertiajs/react';
import { Eye, Heart, MessageCircle, RefreshCw, Repeat2 } from 'lucide-react';
import { useState } from 'react';

import PostMetricsRefreshController from '@/actions/App/Http/Controllers/Posts/PostMetricsRefreshController';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { MetricSparkline } from '@/components/posts/metric-sparkline';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { dayjs } from '@/lib/datetime/dayjs';
import type { PostView } from '@/types/compose';
import type { PostStatsPayload } from '@/types/metrics';

type Props = {
    post: PostView;
};

function StatCell({
    icon,
    value,
    label,
}: {
    icon: React.ReactNode;
    value: number;
    label: string;
}) {
    return (
        <span
            className="flex items-center gap-1 tabular-nums"
            aria-label={`${value.toLocaleString()} ${label}`}
        >
            {icon}
            <span>{value.toLocaleString()}</span>
        </span>
    );
}

function PostStatsCardInner({ post }: Props) {
    const page = usePage();
    const rawStats = (page.props as Record<string, unknown>).stats as
        | PostStatsPayload
        | undefined;

    const [localStats, setLocalStats] = useState<PostStatsPayload | null>(null);
    const stats = localStats ?? rawStats;

    const http = useHttp<Record<string, never>, PostStatsPayload>({});

    function handleRefresh() {
        http.transform(() => ({}));
        void http.post(PostMetricsRefreshController.store(post.id).url, {
            onSuccess: (payload) => {
                setLocalStats(payload);
            },
        });
    }

    if (!stats) {
        return null;
    }

    const hasAnyOk = stats.targets.some(
        (t) => t.status === 'ok' && t.captured_at !== null,
    );

    return (
        <Card size="sm" className="mt-6">
            <CardHeader className="border-b border-border pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-semibold">
                        Post performance
                    </CardTitle>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground"
                        onClick={handleRefresh}
                        disabled={http.processing}
                        aria-label="Refresh metrics"
                    >
                        <RefreshCw
                            className={`size-3 ${http.processing ? 'animate-spin' : ''}`}
                        />
                        Refresh
                    </Button>
                </div>

                {/* Totals row */}
                {hasAnyOk && (
                    <div className="mt-2 flex items-center gap-4 text-[13px] text-muted-foreground">
                        <StatCell
                            icon={<Heart className="size-3.5 text-rose-500" />}
                            value={stats.totals.likes}
                            label="likes"
                        />
                        <StatCell
                            icon={
                                <MessageCircle className="size-3.5 text-sky-500" />
                            }
                            value={stats.totals.comments}
                            label="comments"
                        />
                        <StatCell
                            icon={
                                <Repeat2 className="size-3.5 text-emerald-500" />
                            }
                            value={stats.totals.reposts}
                            label="reposts"
                        />
                    </div>
                )}
            </CardHeader>

            <CardContent className="pt-3">
                {!hasAnyOk &&
                stats.targets.length > 0 &&
                stats.targets.every((t) => t.status === 'ok') ? (
                    <p className="py-4 text-center text-[13px] text-muted-foreground">
                        Collecting — first numbers appear after the next sync.
                    </p>
                ) : (
                    <div className="divide-y divide-border">
                        {stats.targets.map((target) => (
                            <div
                                key={target.id}
                                className="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                {/* Platform + account */}
                                <div className="flex min-w-0 flex-1 items-center gap-2">
                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-foreground">
                                        <PlatformGlyph
                                            platform={target.platform}
                                            size={13}
                                        />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="truncate text-[13px] leading-tight font-medium">
                                            {target.display_name ??
                                                target.handle ??
                                                target.platform}
                                        </p>
                                        {target.handle && (
                                            <p className="truncate text-[11px] text-muted-foreground">
                                                {target.handle}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Stats or status */}
                                <div className="shrink-0 text-right">
                                    {target.status === 'unsupported' ? (
                                        <p className="text-[11px] text-muted-foreground">
                                            Not available on {target.platform}
                                        </p>
                                    ) : target.status === 'rate_limited' ||
                                      target.status === 'failed' ? (
                                        <p className="text-[11px] text-muted-foreground">
                                            Updating soon
                                        </p>
                                    ) : target.status === 'ok' &&
                                      target.captured_at === null ? (
                                        <p className="text-[11px] text-muted-foreground">
                                            Collecting…
                                        </p>
                                    ) : (
                                        <div className="flex items-end gap-3 text-[12px] text-muted-foreground">
                                            <div className="flex flex-col items-end gap-0.5">
                                                <StatCell
                                                    icon={
                                                        <Heart className="size-3 text-rose-500" />
                                                    }
                                                    value={target.likes}
                                                    label="likes"
                                                />
                                                <MetricSparkline
                                                    values={target.series.map(
                                                        (s) => s.likes,
                                                    )}
                                                    color="var(--color-rose-400)"
                                                />
                                            </div>
                                            <div className="flex flex-col items-end gap-0.5">
                                                <StatCell
                                                    icon={
                                                        <MessageCircle className="size-3 text-sky-500" />
                                                    }
                                                    value={target.comments}
                                                    label="comments"
                                                />
                                                <MetricSparkline
                                                    values={target.series.map(
                                                        (s) => s.comments,
                                                    )}
                                                    color="var(--color-sky-400)"
                                                />
                                            </div>
                                            <div className="flex flex-col items-end gap-0.5">
                                                <StatCell
                                                    icon={
                                                        <Repeat2 className="size-3 text-emerald-500" />
                                                    }
                                                    value={target.reposts}
                                                    label="reposts"
                                                />
                                                <MetricSparkline
                                                    values={target.series.map(
                                                        (s) => s.reposts,
                                                    )}
                                                    color="var(--color-emerald-400)"
                                                />
                                            </div>
                                            {target.impressions !== null && (
                                                <div className="flex flex-col items-end gap-0.5">
                                                    <StatCell
                                                        icon={
                                                            <Eye className="size-3 text-violet-500" />
                                                        }
                                                        value={
                                                            target.impressions
                                                        }
                                                        label="views"
                                                    />
                                                    <MetricSparkline
                                                        values={target.series.map(
                                                            (s) =>
                                                                s.impressions ??
                                                                0,
                                                        )}
                                                        color="var(--color-violet-400)"
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {target.captured_at && (
                                        <p className="mt-0.5 text-[10px] text-muted-foreground/60">
                                            {dayjs(
                                                target.captured_at,
                                            ).fromNow()}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function PostStatsCardSkeleton() {
    return (
        <Card size="sm" className="mt-6">
            <CardHeader className="border-b border-border pb-3">
                <div className="flex items-center justify-between">
                    <Skeleton className="h-4 w-28" />
                    <Skeleton className="h-7 w-16" />
                </div>
                <div className="mt-2 flex gap-4">
                    <Skeleton className="h-3.5 w-12" />
                    <Skeleton className="h-3.5 w-14" />
                    <Skeleton className="h-3.5 w-12" />
                </div>
            </CardHeader>
            <CardContent className="space-y-3 pt-3">
                {[0, 1].map((i) => (
                    <div key={i} className="flex items-center gap-3 py-2">
                        <Skeleton className="size-7 rounded-full" />
                        <div className="flex-1 space-y-1">
                            <Skeleton className="h-3.5 w-24" />
                            <Skeleton className="h-2.5 w-16" />
                        </div>
                        <div className="flex gap-3">
                            <Skeleton className="h-3.5 w-8" />
                            <Skeleton className="h-3.5 w-8" />
                            <Skeleton className="h-3.5 w-8" />
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

export function PostStatsCard({ post }: Props) {
    return (
        <Deferred data="stats" fallback={<PostStatsCardSkeleton />}>
            <PostStatsCardInner post={post} />
        </Deferred>
    );
}

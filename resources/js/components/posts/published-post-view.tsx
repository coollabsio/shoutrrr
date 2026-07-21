import { Deferred, router, useHttp, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ExternalLink,
    Eye,
    Heart,
    MessageCircle,
    RefreshCw,
    Repeat2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostMetricsRefreshController from '@/actions/App/Http/Controllers/Posts/PostMetricsRefreshController';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { TargetStatusChips } from '@/components/compose/target-status-chips';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { dayjs } from '@/lib/datetime/dayjs';
import { formatCompact, formatFull } from '@/lib/format';
import { LinkedText } from '@/lib/linked-text';
import {
    type EngagementKey,
    engagementItems,
} from '@/lib/posts/engagement-metrics';
import { platformLabel, postPermalink } from '@/lib/posts/permalink';
import { cn } from '@/lib/utils';
import type {
    MediaView,
    PlatformName,
    PostView,
    TargetView,
} from '@/types/compose';
import type { PostStatTarget, PostStatsPayload } from '@/types/metrics';

type Props = {
    post: PostView;
    /** Whether the analytics feature is on; off hides every number. */
    showMetrics: boolean;
};

const PLATFORM_ACCENT: Record<PlatformName, string> = {
    x: 'text-foreground',
    bluesky: 'text-sky-500',
    linkedin: 'text-blue-600',
    facebook: 'text-[#1877F2]',
    instagram: 'text-[#E4405F]',
    threads: 'text-foreground',
    discord: 'text-[#5865F2]',
};

const METRIC_ICON: Record<EngagementKey, typeof Heart> = {
    likes: Heart,
    comments: MessageCircle,
    reposts: Repeat2,
    views: Eye,
};

/**
 * lucide's MessageCircle is drawn heavier (and carries a chat tail) than the
 * leaner Repeat2/Eye/Heart glyphs, so at a shared box it reads oversized in a
 * tight number row. A hair smaller brings it back to optical parity.
 */
const METRIC_ICON_CLASS: Partial<Record<EngagementKey, string>> = {
    comments: 'size-[0.875rem]',
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

/** Media this target actually carries — its override list, or all post media. */
function resolveMedia(target: TargetView, media: MediaView[]): MediaView[] {
    const ids = target.content_override?.media_ids;
    if (ids && ids.length > 0) {
        return ids
            .map((id) => media.find((m) => m.id === id))
            .filter((m): m is MediaView => m !== undefined);
    }

    return media;
}

function MediaGrid({ media }: { media: MediaView[] }) {
    if (media.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'mt-3 grid overflow-hidden rounded-2xl border border-border bg-muted/40',
                media.length === 1 ? 'grid-cols-1' : 'grid-cols-2',
            )}
        >
            {media.slice(0, 4).map((item) => (
                <div
                    key={item.id}
                    className="relative aspect-video overflow-hidden bg-muted"
                >
                    {item.kind === 'video' ? (
                        <video
                            src={item.url}
                            className="size-full object-cover"
                            muted
                        />
                    ) : (
                        <img
                            src={item.url}
                            alt={item.alt_text ?? ''}
                            className="size-full object-cover"
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function ActionBar({
    platform,
    stat,
}: {
    platform: PlatformName;
    stat: PostStatTarget;
}) {
    const items = engagementItems(platform, stat);

    return (
        <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1.5 border-t border-border pt-3 text-muted-foreground">
            {items.map((item) => {
                const Icon = METRIC_ICON[item.key];

                return (
                    <span
                        key={item.key}
                        className="inline-flex items-center gap-1.5 text-[13px] tabular-nums"
                        title={`${formatFull(item.value)} ${item.label}`}
                        aria-label={`${formatFull(item.value)} ${item.label}`}
                    >
                        <span className="grid size-4 place-items-center">
                            <Icon
                                className={cn(
                                    'size-4',
                                    METRIC_ICON_CLASS[item.key],
                                )}
                                strokeWidth={2}
                                aria-hidden
                            />
                        </span>
                        <span className="font-medium text-foreground">
                            {formatCompact(item.value)}
                        </span>
                    </span>
                );
            })}
        </div>
    );
}

/** A muted, non-numeric explanation for targets we can't show counts for yet. */
function ActionBarNote({ children }: { children: React.ReactNode }) {
    return (
        <p className="mt-3 border-t border-border pt-3 text-[12px] text-muted-foreground">
            {children}
        </p>
    );
}

function ActionBarSkeleton() {
    return (
        <div className="mt-3 flex gap-5 border-t border-border pt-3">
            {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-4 w-12" />
            ))}
        </div>
    );
}

function metricsBar(
    target: TargetView,
    stat: PostStatTarget | undefined,
    showMetrics: boolean,
    loading: boolean,
) {
    if (!showMetrics) {
        return null;
    }
    if (loading || stat === undefined) {
        return <ActionBarSkeleton />;
    }
    if (stat.status === 'unsupported') {
        return (
            <ActionBarNote>
                Engagement isn’t available on {platformLabel(target.platform)}.
            </ActionBarNote>
        );
    }
    if (stat.status === 'rate_limited' || stat.status === 'failed') {
        return (
            <ActionBarNote>
                Couldn’t refresh numbers — trying again soon.
            </ActionBarNote>
        );
    }
    if (stat.captured_at === null) {
        return (
            <ActionBarNote>
                Collecting — numbers appear after the next sync.
            </ActionBarNote>
        );
    }

    return <ActionBar platform={target.platform} stat={stat} />;
}

function PublishedCard({
    target,
    media,
    publishedAt,
    stat,
    showMetrics,
    loading,
}: {
    target: TargetView;
    media: MediaView[];
    publishedAt: string | null;
    stat: PostStatTarget | undefined;
    showMetrics: boolean;
    loading: boolean;
}) {
    const name =
        target.display_name ?? target.handle ?? platformLabel(target.platform);
    const permalink =
        target.status === 'published'
            ? postPermalink(target.platform, target.handle, target.remote_id)
            : null;
    const when = publishedAt ? dayjs(publishedAt).fromNow() : 'now';
    const cardMedia = resolveMedia(target, media);
    const sections = target.sections.length > 0 ? target.sections : [''];
    const isThread = sections.length > 1;

    return (
        <article className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm ring-1 ring-foreground/5">
            <div className="flex items-center gap-2 border-b border-border bg-muted/30 px-4 py-2">
                <span
                    className={cn(
                        'grid size-5 place-items-center rounded-md border bg-background',
                        PLATFORM_ACCENT[target.platform],
                    )}
                >
                    <PlatformGlyph platform={target.platform} size={11} />
                </span>
                <span className="text-[12px] font-medium text-muted-foreground">
                    {platformLabel(target.platform)}
                    {isThread && ` · ${sections.length} posts`}
                </span>
                {permalink && (
                    <a
                        href={permalink}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="ml-auto inline-flex items-center gap-1 text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        View on {platformLabel(target.platform)}
                        <ExternalLink className="size-3" aria-hidden />
                    </a>
                )}
            </div>

            <div className="p-4">
                {sections.map((section, index) => {
                    const isLast = index === sections.length - 1;

                    return (
                        <article
                            key={index}
                            className="relative grid grid-cols-[40px_minmax(0,1fr)] gap-3"
                        >
                            {!isLast && target.platform !== 'linkedin' && (
                                <div
                                    className="absolute top-11 bottom-0 left-5 w-px bg-border"
                                    aria-hidden
                                />
                            )}
                            <Avatar className="z-10 size-10 bg-background">
                                <AvatarImage
                                    src={target.avatar_url ?? undefined}
                                />
                                <AvatarFallback className="text-[11px] font-semibold">
                                    {initials(name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className={cn('min-w-0', !isLast && 'pb-4')}>
                                <div className="flex min-w-0 items-center gap-1.5 text-[13px] leading-5">
                                    <span className="truncate font-semibold text-foreground">
                                        {name}
                                    </span>
                                    {target.platform !== 'linkedin' && (
                                        <CheckCircle2
                                            className="size-3.5 shrink-0 fill-sky-500 text-background"
                                            aria-hidden
                                        />
                                    )}
                                    <span className="truncate text-muted-foreground">
                                        {target.handle
                                            ? `${target.handle} · `
                                            : ''}
                                        {when}
                                    </span>
                                </div>
                                <p className="mt-0.5 text-[14px] leading-6 wrap-anywhere whitespace-pre-wrap text-foreground">
                                    <LinkedText
                                        text={section}
                                        platform={target.platform}
                                    />
                                </p>
                                {index === 0 && cardMedia.length > 0 && (
                                    <MediaGrid media={cardMedia} />
                                )}
                            </div>
                        </article>
                    );
                })}

                {metricsBar(target, stat, showMetrics, loading)}
            </div>
        </article>
    );
}

function TotalCell({
    icon: Icon,
    value,
    label,
    accent,
    loading,
}: {
    icon: typeof Heart;
    value: number;
    label: string;
    accent: string;
    loading: boolean;
}) {
    return (
        <div className="flex flex-col gap-1">
            <span className="inline-flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground">
                <Icon className={cn('size-3.5', accent)} aria-hidden />
                {label}
            </span>
            {loading ? (
                <Skeleton className="h-7 w-14" />
            ) : (
                <span
                    className="text-2xl font-semibold tracking-tight text-foreground tabular-nums"
                    title={formatFull(value)}
                >
                    {formatCompact(value)}
                </span>
            )}
        </div>
    );
}

function SummaryStrip({
    post,
    platformCount,
    stats,
    showMetrics,
    loading,
}: {
    post: PostView;
    platformCount: number;
    stats: PostStatsPayload | null;
    showMetrics: boolean;
    loading: boolean;
}) {
    const when = post.published_at ? dayjs(post.published_at).fromNow() : null;
    const dotClass =
        post.status === 'published'
            ? 'bg-emerald-500'
            : post.status === 'partial'
              ? 'bg-amber-500'
              : 'bg-muted-foreground';

    const viewsTotal = (stats?.targets ?? []).reduce(
        (sum, t) => sum + (t.impressions ?? 0),
        0,
    );
    const hasViews = (stats?.targets ?? []).some((t) => t.impressions !== null);
    const synced = stats?.captured_at
        ? dayjs(stats.captured_at).fromNow()
        : null;

    const refreshHttp = useHttp<Record<string, never>, unknown>({});

    function refreshMetrics() {
        void refreshHttp
            .post(PostMetricsRefreshController.store(post.id).url, {
                onSuccess: () => router.reload({ only: ['stats'] }),
                onHttpException: () => {
                    toast.error('Could not refresh metrics.');
                },
                onNetworkError: () => {
                    toast.error('Could not reach the server.');
                },
            })
            .catch(() => {});
    }

    return (
        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm ring-1 ring-foreground/5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-[13px] text-muted-foreground">
                    <span
                        className={cn('size-2 rounded-full', dotClass)}
                        aria-hidden
                    />
                    <span className="font-medium text-foreground capitalize">
                        {post.status}
                    </span>
                    {when && <span>· {when}</span>}
                    <span>
                        · {platformCount}{' '}
                        {platformCount === 1 ? 'platform' : 'platforms'}
                    </span>
                </div>

                {showMetrics && (
                    <div className="flex items-center gap-2">
                        {synced && !loading && (
                            <span className="text-[12px] text-muted-foreground">
                                Synced {synced}
                            </span>
                        )}
                        {!loading && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 gap-1.5 px-2 text-[12px] text-muted-foreground hover:text-foreground"
                                disabled={refreshHttp.processing}
                                onClick={refreshMetrics}
                            >
                                <RefreshCw
                                    className={cn(
                                        'size-3.5',
                                        refreshHttp.processing &&
                                            'animate-spin',
                                    )}
                                    aria-hidden
                                />
                                Refresh
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {showMetrics && (
                <div className="mt-4 flex flex-wrap gap-x-10 gap-y-4">
                    <TotalCell
                        icon={Heart}
                        accent="text-rose-500"
                        value={stats?.totals.likes ?? 0}
                        label="Likes"
                        loading={loading}
                    />
                    <TotalCell
                        icon={MessageCircle}
                        accent="text-sky-500"
                        value={stats?.totals.comments ?? 0}
                        label="Comments"
                        loading={loading}
                    />
                    <TotalCell
                        icon={Repeat2}
                        accent="text-emerald-500"
                        value={stats?.totals.reposts ?? 0}
                        label="Reposts"
                        loading={loading}
                    />
                    {(loading || hasViews) && (
                        <TotalCell
                            icon={Eye}
                            accent="text-violet-500"
                            value={viewsTotal}
                            label="Views"
                            loading={loading}
                        />
                    )}
                </div>
            )}
        </div>
    );
}

function PublishedBody({
    post,
    showMetrics,
    loading = false,
}: Props & { loading?: boolean }) {
    const page = usePage();
    const rawStats = (page.props as Record<string, unknown>).stats as
        | PostStatsPayload
        | undefined;

    const publishedTargets = post.targets.filter(
        (t) => t.status === 'published',
    );
    const otherTargets = post.targets.filter((t) => t.status !== 'published');

    const [selectedId, setSelectedId] = useState(publishedTargets[0]?.id ?? '');
    const stats = rawStats ?? null;
    const statsById = new Map(
        (stats?.targets ?? []).map((t) => [t.id, t] as const),
    );

    const selectedTarget =
        publishedTargets.find((t) => t.id === selectedId) ??
        publishedTargets[0];

    return (
        <section className="mt-6 space-y-4">
            <SummaryStrip
                post={post}
                platformCount={publishedTargets.length}
                stats={stats}
                showMetrics={showMetrics}
                loading={loading}
            />

            {publishedTargets.length > 1 && (
                <ToggleGroup
                    value={selectedTarget ? [selectedTarget.id] : []}
                    size="sm"
                    variant="outline"
                    onValueChange={(value) => {
                        const v = value[0];
                        if (v) {
                            setSelectedId(v);
                        }
                    }}
                    className="flex flex-wrap justify-start"
                >
                    {publishedTargets.map((target) => (
                        <ToggleGroupItem
                            key={target.id}
                            value={target.id}
                            aria-label={platformLabel(target.platform)}
                            className="gap-1.5 px-3 text-xs"
                        >
                            <PlatformGlyph
                                platform={target.platform}
                                size={13}
                            />
                            {platformLabel(target.platform)}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            )}

            {selectedTarget && (
                <PublishedCard
                    target={selectedTarget}
                    media={post.media}
                    publishedAt={post.published_at}
                    stat={statsById.get(selectedTarget.id)}
                    showMetrics={showMetrics}
                    loading={loading}
                />
            )}

            {otherTargets.length > 0 && (
                <div className="rounded-2xl border border-border bg-card p-4 shadow-sm ring-1 ring-foreground/5">
                    <p className="mb-2.5 text-[12px] font-medium text-muted-foreground">
                        Didn’t publish everywhere
                    </p>
                    <TargetStatusChips targets={otherTargets} />
                </div>
            )}
        </section>
    );
}

/**
 * Read-only "how it landed" view for a published post: a totals strip plus one
 * faithful platform card per target with the real engagement numbers wired into
 * each network's own action bar. Metrics arrive as a deferred prop, so content
 * renders immediately while the numbers skeleton-load.
 */
export function PublishedPostView({ post, showMetrics }: Props) {
    if (!showMetrics) {
        return <PublishedBody post={post} showMetrics={false} />;
    }

    return (
        <Deferred
            data="stats"
            fallback={<PublishedBody post={post} showMetrics loading />}
        >
            <PublishedBody post={post} showMetrics />
        </Deferred>
    );
}

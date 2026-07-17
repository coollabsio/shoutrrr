import { router } from '@inertiajs/react';
import { Film, Image as ImageIcon } from 'lucide-react';
import { Fragment } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import type { ChipTarget } from '@/components/compose/target-status-chips';
import { Badge } from '@/components/ui/badge';
import { dayjs } from '@/lib/datetime/dayjs';
import { postStatusMeta } from '@/lib/posts/status';
import { cn } from '@/lib/utils';
import type { PlatformName, PostStatus } from '@/types/compose';

import { PostRowActions } from './post-row-actions';

export type { PostStatus } from '@/types/compose';

export type PostRowData = {
    id: string;
    base_text: string;
    status: PostStatus;
    status_label: string;
    author: string | null;
    target_count: number;
    updated_at: string;
    scheduled_at: string | null;
    published_at: string | null;
    platforms: PlatformName[];
    targets: ChipTarget[];
    media_count: number;
    media_preview: { kind: 'image' | 'video'; url: string | null } | null;
};

function formatWhen(dateStr: string): { when: string; time: string } {
    const d = dayjs(dateStr);
    const startOfToday = dayjs().startOf('day');
    let when: string;
    if (d.isSame(startOfToday, 'day')) {
        when = 'Today';
    } else if (d.isSame(startOfToday.subtract(1, 'day'), 'day')) {
        when = 'Yesterday';
    } else if (d.isSame(startOfToday.add(1, 'day'), 'day')) {
        when = 'Tomorrow';
    } else {
        const diffDays = d.startOf('day').diff(startOfToday, 'day');
        when =
            diffDays > -7 && diffDays < 7 ? d.format('ddd') : d.format('MMM D');
    }
    return { when, time: d.format('h:mm A') };
}

function StatusBadge({ status }: { status: PostStatus }) {
    const meta = postStatusMeta[status] ?? postStatusMeta.draft;
    return <Badge variant={meta.variant}>{meta.label}</Badge>;
}

/**
 * A glanceable attachment preview so a row reveals whether media is attached
 * without opening the post. Images render their own thumbnail; videos (served
 * from a private, signed URL an <img> can't load) fall back to an icon tile. A
 * "+N" badge marks additional attachments beyond the first.
 */
function MediaThumb({
    preview,
    count,
}: {
    preview: NonNullable<PostRowData['media_preview']>;
    count: number;
}) {
    const extra = count - 1;
    const showImage = preview.kind === 'image' && preview.url !== null;

    return (
        <div
            role="img"
            aria-label={`${count} ${count === 1 ? 'attachment' : 'attachments'}`}
            className="relative size-11 shrink-0 overflow-hidden rounded-lg bg-muted ring-1 ring-border/60"
        >
            {showImage ? (
                <img
                    src={preview.url ?? ''}
                    alt=""
                    loading="lazy"
                    className="size-full object-cover"
                />
            ) : (
                <div className="grid size-full place-items-center text-muted-foreground">
                    {preview.kind === 'video' ? (
                        <Film size={16} strokeWidth={1.75} />
                    ) : (
                        <ImageIcon size={16} strokeWidth={1.75} />
                    )}
                </div>
            )}
            {extra > 0 && (
                <span className="absolute right-0.5 bottom-0.5 rounded bg-background/85 px-1 text-[10px] font-medium text-foreground tabular-nums backdrop-blur-sm">
                    +{extra}
                </span>
            )}
        </div>
    );
}

/**
 * The timestamp the row leads with depends on what's relevant for the status:
 * published posts show when they went out, scheduled posts show when they will,
 * and everything else falls back to the last edit. The badge already names the
 * status, so the rail only needs to carry the time.
 */
function rowTimestamp(post: PostRowData): string {
    if (post.status === 'published' || post.status === 'partial') {
        return post.published_at ?? post.updated_at;
    }
    if (
        post.status === 'scheduled' ||
        post.status === 'publishing' ||
        post.status === 'missed'
    ) {
        return post.scheduled_at ?? post.updated_at;
    }

    return post.updated_at;
}

export function PostRow({ post }: { post: PostRowData }) {
    const { when, time } = formatWhen(rowTimestamp(post));
    // Default the optional list fields: an older/partial Inertia payload (e.g. a
    // page loaded before the index exposed per-target data) must not crash the row.
    const platforms = post.platforms ?? [];
    const accounts = post.target_count ?? 0;
    const mediaCount = post.media_count ?? 0;
    const mediaPreview = post.media_preview ?? null;
    const failedCount = (post.targets ?? []).filter(
        (t: ChipTarget) => t.status === 'failed',
    ).length;

    function openCompose() {
        router.visit(ComposerController.show(post.id).url);
    }

    const metaParts: { key: string; node: React.ReactNode }[] = [];

    if (platforms.length > 0) {
        metaParts.push({
            key: 'glyphs',
            node: (
                <span className="inline-flex">
                    {platforms.map((pl, i) => (
                        <span
                            key={pl}
                            aria-label={pl}
                            className={cn(
                                'grid size-[18px] place-items-center rounded-[5px] bg-muted text-foreground',
                                i > 0 && '-ml-1 ring-[1.5px] ring-background',
                            )}
                        >
                            <PlatformGlyph platform={pl} size={10} />
                        </span>
                    ))}
                </span>
            ),
        });
    }

    metaParts.push({
        key: 'accounts',
        node: (
            <span>
                {accounts} {accounts === 1 ? 'account' : 'accounts'}
            </span>
        ),
    });

    // The one actionable signal a list needs: that something failed, and how
    // much. Detail and retry stay in the actions menu / on opening the post.
    if (failedCount > 0) {
        metaParts.push({
            key: 'failed',
            node: (
                <span className="font-medium text-destructive">
                    {failedCount} failed
                </span>
            ),
        });
    }

    return (
        <div
            // oxlint-disable-next-line prefer-tag-over-role -- actions buttons are nested, can't use <button>
            role="button"
            tabIndex={0}
            aria-label={`Open post: ${post.base_text || 'Untitled draft'}`}
            onClick={openCompose}
            onKeyDown={(e) => {
                if (e.key === 'Enter') openCompose();
            }}
            className="group cursor-pointer border-b border-border px-3 py-3 last:border-b-0 hover:bg-muted/40"
        >
            <div className="grid grid-cols-[64px_1fr_auto] items-start gap-3 sm:grid-cols-[84px_1fr_auto] sm:gap-4">
                {/* Time rail */}
                <div className="pt-0.5 text-[11.5px] text-muted-foreground tabular-nums">
                    <div className="text-[12.5px] font-medium text-foreground">
                        {when}
                    </div>
                    <div className="mt-0.5">{time}</div>
                </div>

                {/* Middle: text + meta, with an optional attachment thumbnail
                    pinned to the right. The text block reserves two lines so
                    every row lines up regardless of status or content length. */}
                <div className="flex min-w-0 items-start gap-3">
                    <div className="min-w-0 flex-1">
                        <p className="line-clamp-2 min-h-[2.75em] text-[13.5px] leading-snug tracking-tight text-foreground">
                            {post.base_text.trim() || (
                                <span className="text-muted-foreground">
                                    Untitled draft
                                </span>
                            )}
                        </p>
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11.5px] text-muted-foreground">
                            {metaParts.map((part, i) => (
                                <Fragment key={part.key}>
                                    {i > 0 && (
                                        <span
                                            className="size-[3px] rounded-full bg-muted-foreground/50"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {part.node}
                                </Fragment>
                            ))}
                        </div>
                    </div>
                    {mediaPreview && (
                        <MediaThumb preview={mediaPreview} count={mediaCount} />
                    )}
                </div>

                {/* Right: badge + actions */}
                <div className="flex items-center gap-1.5">
                    <StatusBadge status={post.status} />
                    <PostRowActions post={post} />
                </div>
            </div>
        </div>
    );
}

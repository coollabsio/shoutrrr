import { router, useHttp } from '@inertiajs/react';
import { format, isToday, isTomorrow, isYesterday, parseISO } from 'date-fns';
import { MoreHorizontal } from 'lucide-react';
import { Fragment, useState } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostController from '@/actions/App/Http/Controllers/Posts/PostController';
import { PlatformGlyph } from '@/components/platform-glyph';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import {
    type ChipTarget,
    TargetStatusChips,
} from '@/pages/compose/TargetStatusChips';
import type { PlatformName, PostStatus, PostView } from '@/pages/compose/types';
import { retry as retryRoute } from '@/routes/posts/targets';

export type { PostStatus } from '@/pages/compose/types';

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
};

// Status badge styling (semantic shadcn classes + tinted overrides where shadcn
// doesn't ship an info/success/warning variant).
const STATUS_META: Record<
    PostStatus,
    {
        variant: 'secondary' | 'outline' | 'destructive' | null;
        className?: string;
        label: string;
    }
> = {
    draft: { variant: 'secondary', label: 'Draft' },
    scheduled: {
        variant: null,
        className:
            'border-transparent bg-blue-500/10 text-blue-600 dark:text-blue-400',
        label: 'Scheduled',
    },
    publishing: {
        variant: null,
        className:
            'border-transparent bg-blue-500/10 text-blue-600 dark:text-blue-400',
        label: 'Publishing',
    },
    published: {
        variant: null,
        className: 'border-transparent bg-emerald-500/10 text-emerald-600',
        label: 'Published',
    },
    partial: {
        variant: null,
        className:
            'border-transparent bg-amber-500/10 text-amber-600 dark:text-amber-500',
        label: 'Partial',
    },
    failed: { variant: 'destructive', label: 'Failed' },
    deleted: { variant: 'secondary', label: 'Deleted' },
};

function formatWhen(dateStr: string): { when: string; time: string } {
    const d = parseISO(dateStr);
    let when: string;
    if (isToday(d)) {
        when = 'Today';
    } else if (isYesterday(d)) {
        when = 'Yesterday';
    } else if (isTomorrow(d)) {
        when = 'Tomorrow';
    } else {
        const diffDays = Math.round(
            (d.getTime() - new Date().setHours(0, 0, 0, 0)) / 86_400_000,
        );
        if (diffDays > -7 && diffDays < 7) {
            when = format(d, 'EEE'); // Mon, Tue …
        } else {
            when = format(d, 'MMM d'); // Jan 5
        }
    }
    return {
        when,
        time: format(d, 'h:mm a').replace('am', 'AM').replace('pm', 'PM'),
    };
}

function StatusBadge({ status }: { status: PostStatus }) {
    const meta = STATUS_META[status] ?? STATUS_META.draft;
    return (
        <Badge
            variant={meta.variant ?? 'outline'}
            className={cn(meta.className)}
        >
            {meta.label}
        </Badge>
    );
}

function PostRowActions({ post }: { post: PostRowData }) {
    const [deleteConfirm, setDeleteConfirm] = useState(false);

    function openCompose() {
        router.visit(ComposerController.show(post.id).url);
    }

    function handleDelete() {
        if (!deleteConfirm) {
            setDeleteConfirm(true);
            return;
        }
        router.delete(PostController.destroy(post.id).url);
    }

    function stopBubble(e: React.MouseEvent | React.KeyboardEvent) {
        e.stopPropagation();
    }

    return (
        // oxlint-disable-next-line prefer-tag-over-role -- wrapper stops row-click bubbling only
        <div role="presentation" onClick={stopBubble} onKeyDown={stopBubble}>
            <DropdownMenu
                onOpenChange={(open) => !open && setDeleteConfirm(false)}
            >
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Post actions"
                        className="size-8 text-muted-foreground hover:text-foreground"
                    >
                        <MoreHorizontal className="size-4" aria-hidden />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem onSelect={openCompose}>
                        Edit
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={handleDelete}
                    >
                        {deleteConfirm ? 'Confirm delete' : 'Delete'}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

export function PostRow({ post }: { post: PostRowData }) {
    const timestampStr = post.scheduled_at ?? post.updated_at;
    const { when, time } = formatWhen(timestampStr);
    // Default the optional list fields: an older/partial Inertia payload (e.g. a
    // page loaded before the index exposed per-target data) must not crash the row.
    const platforms = post.platforms ?? [];
    const accounts = post.target_count ?? 0;

    // The list does not poll; chips seed from the row payload and only the row
    // whose target was just retried live-updates from the retry response.
    const http = useHttp<Record<string, never>, { post: PostView }>({});
    const [targets, setTargets] = useState<ChipTarget[]>(post.targets ?? []);
    const [retryingIds, setRetryingIds] = useState<ReadonlySet<string>>(
        () => new Set(),
    );

    async function retryTarget(targetId: string) {
        if (retryingIds.has(targetId)) {
            return;
        }
        setRetryingIds((prev) => new Set(prev).add(targetId));
        try {
            const result = await http.post(
                retryRoute({ post: post.id, target: targetId }).url,
                { onNetworkError: () => undefined },
            );
            setTargets(result.post.targets);
        } finally {
            setRetryingIds((prev) => {
                const next = new Set(prev);
                next.delete(targetId);

                return next;
            });
        }
    }

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

                {/* Middle: text + meta */}
                <div className="min-w-0">
                    <p className="line-clamp-2 text-[13.5px] leading-snug tracking-tight text-foreground">
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
                    {post.status !== 'draft' &&
                        targets.length > 0 && (
                            // oxlint-disable-next-line prefer-tag-over-role -- stop row-click bubbling on retry
                            <div
                                role="presentation"
                                onClick={(e) => e.stopPropagation()}
                                onKeyDown={(e) => e.stopPropagation()}
                                className="mt-2.5"
                            >
                                <TargetStatusChips
                                    targets={targets}
                                    retryingIds={retryingIds}
                                    onRetry={(targetId) =>
                                        void retryTarget(targetId)
                                    }
                                />
                            </div>
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

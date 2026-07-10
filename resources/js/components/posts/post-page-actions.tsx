import { Link, router, useHttp, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarX,
    MessageCircle,
    RotateCw,
    Share2,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostController from '@/actions/App/Http/Controllers/Posts/PostController';
import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import { useConfirm } from '@/components/common/confirm-dialog';
import {
    defaultPickedAt,
    PickTimePopover,
} from '@/components/compose/pick-time-popover';
import { Button } from '@/components/ui/button';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { dayjs } from '@/lib/datetime/dayjs';
import { postCapabilities } from '@/lib/posts/capabilities';
import { postLiveStatus } from '@/lib/posts/live-status';
import { index as engagementRoute } from '@/routes/engagement';
import { index as postsRoute } from '@/routes/posts';
import { retry as retryRoute } from '@/routes/posts/targets';
import type { PostView } from '@/types/compose';

import { ShareDialog } from './share-dialog';

type Props = {
    post: PostView;
};

/**
 * Contextual actions for a single post's detail page, gated by the same
 * capabilities as the list row. The schedule/publish endpoints return JSON, so
 * mutations go through `useHttp` and then reload the page (mirroring SubmitBar);
 * delete returns a redirect, so it goes through the Inertia router and lands on
 * the posts index. A live "going live in …" line ticks once a minute.
 */
export function PostPageActions({ post }: Props) {
    const { features } = usePage().props;
    const caps = postCapabilities(post);
    const tz = useSchedulingTimezone();
    const confirm = useConfirm();
    const http = useHttp<Record<string, never>, { post: PostView }>({});

    // A post can draw replies only once it's live somewhere, so the jump to its
    // engagement is offered exactly when at least one target has published.
    const hasPublishedTarget = post.targets.some(
        (t) => t.status === 'published',
    );
    const showReplies = Boolean(features?.engagement) && hasPublishedTarget;

    const [rescheduling, setRescheduling] = useState(false);
    const [pickedAt, setPickedAt] = useState<string>(() => defaultPickedAt(tz));
    const [shareOpen, setShareOpen] = useState(false);

    // Re-render each minute so the relative "going live" label stays current.
    const [, setTick] = useState(0);
    useEffect(() => {
        const id = setInterval(() => setTick((n) => n + 1), 60_000);

        return () => clearInterval(id);
    }, []);

    const liveStatus = postLiveStatus(post);

    function refresh() {
        router.visit(ComposerController.show(post.id).url, {
            preserveScroll: true,
        });
    }

    function openReschedule() {
        // A missed post's stored time is in the past, which the picker rejects;
        // only prefill the existing time when it is still in the future.
        const existing = post.scheduled_at;
        setPickedAt(
            existing && dayjs(existing).isAfter(dayjs())
                ? existing
                : defaultPickedAt(tz),
        );
        setRescheduling(true);
    }

    async function saveReschedule() {
        http.transform(() => ({ scheduled_at: pickedAt }));
        await http.put(PostScheduleController.update(post.id).url, {
            onSuccess: refresh,
        });
        setRescheduling(false);
    }

    async function handleUnschedule() {
        const ok = await confirm({
            title: 'Move back to drafts?',
            description:
                'The post will be unscheduled and returned to your drafts.',
            actionLabel: 'Unschedule',
        });
        if (!ok) {
            return;
        }
        http.transform(() => ({ scheduled_at: null }));
        await http.put(PostScheduleController.update(post.id).url, {
            onSuccess: refresh,
        });
    }

    async function handleRetry() {
        const failed = post.targets.find((t) => t.status === 'failed');
        if (!failed) {
            return;
        }
        http.transform(() => ({}));
        await http.post(retryRoute({ post: post.id, target: failed.id }).url, {
            onSuccess: refresh,
        });
    }

    async function handleDelete() {
        const ok = await confirm({
            title: 'Delete post?',
            description:
                post.status === 'draft' || post.status === 'scheduled'
                    ? 'This removes the post. The content is not kept.'
                    : 'Published copies will be removed from connected accounts where possible.',
            actionLabel: 'Delete',
            destructive: true,
        });
        if (!ok) {
            return;
        }
        router.delete(PostController.destroy(post.id).url, {
            onSuccess: () => {
                router.visit(postsRoute().url);
            },
        });
    }

    return (
        <div className="flex shrink-0 items-center gap-1.5">
            {liveStatus && (
                <span className="mr-1 hidden text-[12px] text-muted-foreground tabular-nums sm:inline">
                    {liveStatus}
                </span>
            )}

            {showReplies && (
                <Button
                    size="sm"
                    variant="outline"
                    aria-label="View replies"
                    nativeButton={false}
                    render={
                        <Link
                            href={`${engagementRoute().url}?post=${post.id}`}
                        />
                    }
                >
                    <MessageCircle className="size-3.5" aria-hidden />
                    <span className="hidden sm:inline">Replies</span>
                </Button>
            )}

            {rescheduling ? (
                <>
                    <PickTimePopover
                        value={pickedAt}
                        onChange={setPickedAt}
                        tz={tz}
                    />
                    <Button
                        size="sm"
                        disabled={http.processing}
                        onClick={() => void saveReschedule()}
                    >
                        Save
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setRescheduling(false)}
                    >
                        Cancel
                    </Button>
                </>
            ) : (
                <>
                    {caps.canReschedule && (
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label="Reschedule"
                            onClick={openReschedule}
                        >
                            <CalendarClock className="size-3.5" aria-hidden />
                            <span className="hidden sm:inline">Reschedule</span>
                        </Button>
                    )}
                    {caps.canUnschedule && (
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label="Unschedule"
                            onClick={() => void handleUnschedule()}
                        >
                            <CalendarX className="size-3.5" aria-hidden />
                            <span className="hidden sm:inline">Unschedule</span>
                        </Button>
                    )}
                    {caps.canRetry && (
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label="Retry failed"
                            onClick={() => void handleRetry()}
                        >
                            <RotateCw className="size-3.5" aria-hidden />
                            <span className="hidden sm:inline">
                                Retry failed
                            </span>
                        </Button>
                    )}
                    {post.status !== 'draft' && (
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label="Share"
                            onClick={() => setShareOpen(true)}
                        >
                            <Share2 className="size-3.5" aria-hidden />
                            <span className="hidden sm:inline">Share</span>
                        </Button>
                    )}
                    {caps.canDelete && (
                        <Button
                            size="sm"
                            variant="destructive"
                            aria-label="Delete"
                            onClick={() => void handleDelete()}
                        >
                            <Trash2 className="size-3.5" aria-hidden />
                            <span className="hidden sm:inline">Delete</span>
                        </Button>
                    )}
                </>
            )}

            <ShareDialog
                postId={post.id}
                open={shareOpen}
                onOpenChange={setShareOpen}
            />
        </div>
    );
}

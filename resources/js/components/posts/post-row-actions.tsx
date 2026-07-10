import { router } from '@inertiajs/react';
import dayjs from 'dayjs';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostController from '@/actions/App/Http/Controllers/Posts/PostController';
import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import { useConfirm } from '@/components/common/confirm-dialog';
import {
    defaultPickedAt,
    PickTimePopover,
} from '@/components/compose/pick-time-popover';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { removeById, replaceById } from '@/lib/optimistic';
import { postCapabilities } from '@/lib/posts/capabilities';
import { retry as retryRoute } from '@/routes/posts/targets';
import type { PostView } from '@/types/compose';

import type { PostRowData } from './post-row';
import { ShareDialog } from './share-dialog';

type Props = {
    post: PostRowData;
};

type Mode = 'menu' | 'scheduling' | 'rescheduling';

function stopBubble(e: React.MouseEvent | React.KeyboardEvent) {
    e.stopPropagation();
}

/**
 * The `posts` page prop is a flat array on the dashboard feed but a
 * `{ data: [...] }` page on the posts index. Apply `update` to whichever shape
 * and return the optimistic partial for `router`'s `optimistic` option.
 */
function optimisticPosts(
    props: object,
    update: (list: PostRowData[]) => PostRowData[],
): Record<string, unknown> {
    const current = (props as { posts?: unknown }).posts;
    if (Array.isArray(current)) {
        return { posts: update(current as PostRowData[]) };
    }
    if (current && Array.isArray((current as { data?: unknown }).data)) {
        const paged = current as { data: PostRowData[] };
        return { posts: { ...paged, data: update(paged.data) } };
    }
    return {};
}

export function PostRowActions({ post }: Props) {
    // PostRowData is structurally compatible with the subset postCapabilities reads
    // (.status: PostStatus, .targets[].status: TargetStatus).
    const caps = postCapabilities(post as unknown as PostView);
    const tz = useSchedulingTimezone();
    const confirm = useConfirm();

    const [mode, setMode] = useState<Mode>('menu');
    const [pickedAt, setPickedAt] = useState<string>(() => defaultPickedAt(tz));
    const [shareOpen, setShareOpen] = useState(false);

    function handleEdit() {
        router.visit(ComposerController.show(post.id).url);
    }

    function openSchedule() {
        setPickedAt(defaultPickedAt(tz));
        setMode('scheduling');
    }

    function openReschedule() {
        // A missed post's stored time is in the past, which the picker rejects;
        // only prefill the existing time when it is still in the future.
        const existing = post.scheduled_at;
        const prefill =
            existing && dayjs(existing).isAfter(dayjs())
                ? existing
                : defaultPickedAt(tz);
        setPickedAt(prefill);
        setMode('rescheduling');
    }

    function saveSchedule() {
        router.put(
            PostScheduleController.update(post.id).url,
            { scheduled_at: pickedAt },
            {
                preserveScroll: true,
                optimistic: (props) =>
                    optimisticPosts(props, (list) =>
                        replaceById(list, post.id, (p) => ({
                            ...p,
                            status: 'scheduled',
                            scheduled_at: pickedAt,
                        })),
                    ),
            },
        );
        setMode('menu');
    }

    function cancelSchedule() {
        setMode('menu');
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
        router.put(
            PostScheduleController.update(post.id).url,
            { scheduled_at: null },
            {
                preserveScroll: true,
                optimistic: (props) =>
                    optimisticPosts(props, (list) =>
                        replaceById(list, post.id, (p) => ({
                            ...p,
                            status: 'draft',
                            scheduled_at: null,
                        })),
                    ),
            },
        );
    }

    function handleRetry() {
        const failedTarget = post.targets.find((t) => t.status === 'failed');
        if (!failedTarget) {
            return;
        }
        router.post(
            retryRoute({ post: post.id, target: failedTarget.id }).url,
            {},
            {
                preserveScroll: true,
                optimistic: (props) =>
                    optimisticPosts(props, (list) =>
                        replaceById(list, post.id, (p) => ({
                            ...p,
                            targets: p.targets.map((t) =>
                                t.id === failedTarget.id
                                    ? { ...t, status: 'pending' }
                                    : t,
                            ),
                        })),
                    ),
            },
        );
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
        // The confirm dialog lives at the app root, so this optimistic removal
        // can safely unmount the row without stranding an open modal.
        router.delete(PostController.destroy(post.id).url, {
            preserveScroll: true,
            optimistic: (props) =>
                optimisticPosts(props, (list) => removeById(list, post.id)),
        });
    }

    // Scheduling / rescheduling inline picker replaces the dropdown
    if (mode === 'scheduling' || mode === 'rescheduling') {
        return (
            // oxlint-disable-next-line prefer-tag-over-role -- wrapper stops row-click bubbling only
            <div
                role="presentation"
                onClick={stopBubble}
                onKeyDown={stopBubble}
                className="flex items-center gap-1.5"
            >
                <PickTimePopover
                    value={pickedAt}
                    onChange={setPickedAt}
                    tz={tz}
                />
                <Button
                    size="sm"
                    className="h-8 text-[12.5px]"
                    onClick={saveSchedule}
                >
                    Save
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 text-[12.5px]"
                    onClick={cancelSchedule}
                >
                    Cancel
                </Button>
            </div>
        );
    }

    return (
        // oxlint-disable-next-line prefer-tag-over-role -- wrapper stops row-click bubbling only
        <div role="presentation" onClick={stopBubble} onKeyDown={stopBubble}>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Post actions"
                            className="size-8 text-muted-foreground hover:text-foreground"
                        />
                    }
                >
                    <MoreHorizontal className="size-4" aria-hidden />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    {caps.canEdit && (
                        <DropdownMenuItem onClick={handleEdit}>
                            Edit
                        </DropdownMenuItem>
                    )}
                    {caps.canSchedule && (
                        <DropdownMenuItem onClick={openSchedule}>
                            Schedule&hellip;
                        </DropdownMenuItem>
                    )}
                    {caps.canReschedule && (
                        <DropdownMenuItem onClick={openReschedule}>
                            Reschedule&hellip;
                        </DropdownMenuItem>
                    )}
                    {caps.canRetry && (
                        <DropdownMenuItem onClick={handleRetry}>
                            Retry failed
                        </DropdownMenuItem>
                    )}
                    {caps.canUnschedule && (
                        <DropdownMenuItem
                            onClick={() => void handleUnschedule()}
                        >
                            Unschedule
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onClick={() => setShareOpen(true)}>
                        Share&hellip;
                    </DropdownMenuItem>
                    {caps.canDelete && (
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() => void handleDelete()}
                        >
                            Delete
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Share dialog */}
            <ShareDialog
                postId={post.id}
                open={shareOpen}
                onOpenChange={setShareOpen}
            />
        </div>
    );
}

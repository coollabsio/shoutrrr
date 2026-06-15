import { router, useHttp } from '@inertiajs/react';
import dayjs from 'dayjs';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostController from '@/actions/App/Http/Controllers/Posts/PostController';
import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSchedulingTimezone } from '@/hooks/use-scheduling-timezone';
import { postCapabilities } from '@/lib/posts/capabilities';
import {
    defaultPickedAt,
    PickTimePopover,
} from '@/pages/compose/PickTimePopover';
import type { PostView } from '@/pages/compose/types';
import { retry as retryRoute } from '@/routes/posts/targets';

import type { PostRowData } from './post-row';
import { ShareDialog } from './share-dialog';

type Props = {
    post: PostRowData;
};

type Mode = 'menu' | 'scheduling' | 'rescheduling';
type ConfirmKind = 'delete' | 'unschedule' | null;

function stopBubble(e: React.MouseEvent | React.KeyboardEvent) {
    e.stopPropagation();
}

export function PostRowActions({ post }: Props) {
    // PostRowData is structurally compatible with the subset postCapabilities reads
    // (.status: PostStatus, .targets[].status: TargetStatus).
    const caps = postCapabilities(post as unknown as PostView);
    const tz = useSchedulingTimezone();

    const [mode, setMode] = useState<Mode>('menu');
    const [pickedAt, setPickedAt] = useState<string>(() => defaultPickedAt(tz));
    const [confirmKind, setConfirmKind] = useState<ConfirmKind>(null);
    const [shareOpen, setShareOpen] = useState(false);

    const http = useHttp<Record<string, never>, Record<string, never>>({});

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

    async function saveSchedule() {
        http.transform(() => ({ scheduled_at: pickedAt }));
        await http.put(PostScheduleController.update(post.id).url, {
            onNetworkError: () => undefined,
        });
        setMode('menu');
        router.reload({ only: ['posts'] });
    }

    function cancelSchedule() {
        setMode('menu');
    }

    function handleUnschedule() {
        setConfirmKind('unschedule');
    }

    async function confirmUnschedule() {
        http.transform(() => ({ scheduled_at: null }));
        await http.put(PostScheduleController.update(post.id).url, {
            onNetworkError: () => undefined,
        });
        router.reload({ only: ['posts'] });
    }

    async function handleRetry() {
        const failedTarget = post.targets.find((t) => t.status === 'failed');
        if (!failedTarget) {
            return;
        }
        http.transform(() => ({}));
        await http.post(
            retryRoute({ post: post.id, target: failedTarget.id }).url,
            { onNetworkError: () => undefined },
        );
        router.reload({ only: ['posts'] });
    }

    function handleDelete() {
        setConfirmKind('delete');
    }

    function confirmDelete() {
        router.delete(PostController.destroy(post.id).url);
    }

    const deleteDescription =
        post.status === 'draft' || post.status === 'scheduled'
            ? 'This removes the post. The content is not kept.'
            : 'Published copies will be removed from connected accounts where possible.';

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
                    onClick={() => void saveSchedule()}
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
                <DropdownMenuContent align="end" className="w-48">
                    {caps.canEdit && (
                        <DropdownMenuItem onSelect={handleEdit}>
                            Edit
                        </DropdownMenuItem>
                    )}
                    {caps.canSchedule && (
                        <DropdownMenuItem onSelect={openSchedule}>
                            Schedule&hellip;
                        </DropdownMenuItem>
                    )}
                    {caps.canReschedule && (
                        <DropdownMenuItem onSelect={openReschedule}>
                            Reschedule&hellip;
                        </DropdownMenuItem>
                    )}
                    {caps.canRetry && (
                        <DropdownMenuItem onSelect={() => void handleRetry()}>
                            Retry failed
                        </DropdownMenuItem>
                    )}
                    {caps.canUnschedule && (
                        <DropdownMenuItem onSelect={handleUnschedule}>
                            Unschedule
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => setShareOpen(true)}>
                        Share&hellip;
                    </DropdownMenuItem>
                    {caps.canDelete && (
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={handleDelete}
                        >
                            Delete
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Unschedule confirmation */}
            <AlertDialog
                open={confirmKind === 'unschedule'}
                onOpenChange={(open) => !open && setConfirmKind(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Move back to drafts?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The post will be unscheduled and returned to your
                            drafts.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => void confirmUnschedule()}
                        >
                            Unschedule
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Delete confirmation */}
            <AlertDialog
                open={confirmKind === 'delete'}
                onOpenChange={(open) => !open && setConfirmKind(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete post?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleteDescription}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Share dialog */}
            <ShareDialog
                postId={post.id}
                open={shareOpen}
                onOpenChange={setShareOpen}
            />
        </div>
    );
}

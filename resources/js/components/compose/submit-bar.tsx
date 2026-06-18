import { Link, router, useHttp } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { celebrate } from '@/lib/compose/celebrate';
import type { ScheduleTray } from '@/lib/compose/composer-state';
import {
    OPTIMISTIC_PUBLISH,
    OPTIMISTIC_SCHEDULE,
    type OptimisticSubmit,
} from '@/lib/compose/publish-status';
import { cn } from '@/lib/utils';
import { publish, queue } from '@/routes/posts';
import type { PostView } from '@/types/compose';

type Props = {
    tray: ScheduleTray;
    postId: string | null;
    disabled?: boolean;
    /** True while a media attachment is still uploading — blocks publishing. */
    uploading?: boolean;
    /**
     * Flush the autosave and resolve once the draft (incl. media) is persisted.
     * Awaited before publishing so the publish never races the save that
     * attaches media to the post.
     */
    onSaveDraft: () => Promise<void>;
    /** Ensure a persisted post id before publishing; returns the post id. */
    onEnsurePost: () => Promise<string>;
    /** When in queue mode, true if there is no slot to queue into (no schedule, full, loading, or error). */
    queueDisabled?: boolean;
    /**
     * Flip the live status chips to their in-flight state instantly; returns a
     * `revert` to restore the prior snapshot if the request fails.
     */
    onOptimisticSubmit: (optimistic: OptimisticSubmit) => () => void;
    /** Adopt the server's post after a successful publish/queue/schedule. */
    onServerPost: (post: PostView) => void;
};

export function SubmitBar({
    tray,
    postId,
    disabled,
    uploading = false,
    onSaveDraft,
    onEnsurePost,
    queueDisabled,
    onOptimisticSubmit,
    onServerPost,
}: Props) {
    // useHttp verbs take NO inline data — the body is injected via transform()
    // at submit time so it always reflects the latest reducer state.
    const http = useHttp<Record<string, never>, { post: PostView }>({});
    const [noSlot, setNoSlot] = useState(false);
    const [pastTime, setPastTime] = useState(false);

    const submitLabel =
        tray.mode === 'now'
            ? 'Publish now'
            : tray.mode === 'queue'
              ? 'Add to queue'
              : 'Schedule';

    async function handleSubmit() {
        setNoSlot(false);
        setPastTime(false);
        // Flush pending edits AND wait for them to persist before publishing —
        // otherwise the publish request races the save that attaches media to
        // the post, and the post publishes without its media.
        await onSaveDraft();
        const id = postId ?? (await onEnsurePost());
        if (!id) {
            return;
        }

        // Shared success path for all three modes: celebrate the post going out,
        // adopt the server snapshot, then reload the compose page.
        const onSuccess = ({ post }: { post: PostView }) => {
            celebrate();
            onServerPost(post);
            router.visit(ComposerController.show(id).url);
        };

        if (tray.mode === 'now') {
            // Flip the chips to "Publishing" instantly; revert if the call fails.
            const revert = onOptimisticSubmit(OPTIMISTIC_PUBLISH);
            http.transform(() => ({}));
            await http.post(publish(id).url, {
                onSuccess,
                onHttpException: revert,
                onNetworkError: revert,
            });

            return;
        }

        if (tray.mode === 'queue') {
            // Flip the chips to "Queued" instantly; revert if the call fails.
            const revert = onOptimisticSubmit(OPTIMISTIC_SCHEDULE);
            http.transform(() => ({}));
            await http.post(queue(id).url, {
                onSuccess,
                // 422 = no open slot in the workspace posting schedule.
                onHttpException: (response) => {
                    revert();
                    if (response.status === 422) {
                        setNoSlot(true);
                    }
                },
                onNetworkError: revert,
            });

            return;
        }

        // mode === 'pick' → schedule at the chosen time (existing M2 path).
        const revert = onOptimisticSubmit(OPTIMISTIC_SCHEDULE);
        http.transform(() => ({ scheduled_at: tray.pickedAt }));
        await http.put(PostScheduleController.update(id).url, {
            onSuccess,
            // 422 = the chosen time is in the past (server guard).
            onHttpException: (response) => {
                revert();
                if (response.status === 422) {
                    setPastTime(true);
                }
            },
            onNetworkError: revert,
        });
    }

    const submitButton = (
        <TrayButton
            variant="primary"
            disabled={
                disabled ||
                uploading ||
                http.processing ||
                (tray.mode === 'queue' && Boolean(queueDisabled))
            }
            onClick={() => void handleSubmit()}
            className="flex-1 sm:flex-none"
        >
            <Send className="size-3.5" aria-hidden="true" />
            <span>{submitLabel}</span>
            <kbd className="ml-0.5 hidden h-4 items-center rounded border border-primary-foreground/25 bg-primary-foreground/15 px-1 font-mono text-[10px] leading-none font-normal text-primary-foreground/90 sm:inline-flex">
                ⌘↵
            </kbd>
        </TrayButton>
    );

    return (
        <div className="flex flex-col items-stretch gap-1.5 sm:items-end sm:justify-self-end">
            <div className="flex items-center gap-1.5">
                <TrayButton
                    onClick={() => void onSaveDraft()}
                    disabled={disabled}
                    className="flex-1 sm:flex-none"
                >
                    Save draft
                </TrayButton>
                {/* A disabled button emits no hover events, so wrap it in a
                    focusable span that carries the tooltip explaining the block. */}
                {uploading ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span tabIndex={0} className="flex-1 sm:flex-none">
                                {submitButton}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            Wait for media to finish uploading.
                        </TooltipContent>
                    </Tooltip>
                ) : (
                    submitButton
                )}
            </div>
            {noSlot && (
                <p className="text-[12px] text-muted-foreground">
                    No open slot in your posting schedule.{' '}
                    <Link
                        href={PostingScheduleController.show().url}
                        className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                    >
                        Add slots
                    </Link>
                </p>
            )}
            {pastTime && (
                <p className="text-[12px] text-destructive">
                    That time has already passed — pick a time in the future.
                </p>
            )}
        </div>
    );
}

type TrayButtonProps = {
    children: ReactNode;
    variant?: 'outline' | 'primary';
    disabled?: boolean;
    onClick?: () => void;
    className?: string;
};

function TrayButton({
    children,
    variant = 'outline',
    disabled = false,
    onClick,
    className,
}: TrayButtonProps) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'inline-flex h-9 items-center justify-center gap-1.5 rounded-md border px-3 text-[12.5px] font-medium transition-[background,border-color,transform] duration-[120ms] active:scale-[0.985] sm:h-8',
                variant === 'outline' &&
                    'border-border bg-background text-foreground hover:bg-muted disabled:opacity-50',
                variant === 'primary' &&
                    'border-primary bg-primary text-primary-foreground shadow-[0_1px_2px_0_rgb(0_0_0/0.04)] hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
        >
            {children}
        </button>
    );
}

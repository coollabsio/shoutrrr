import { Link, router, useHttp } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { index as billingRoute } from '@/routes/billing';
import { publish, queue } from '@/routes/posts';
import type { PostView } from '@/types/compose';

type Props = {
    tray: ScheduleTray;
    postId: string | null;
    disabled?: boolean;
    /** True while a media attachment is still uploading — blocks publishing. */
    uploading?: boolean;
    /** Selected destination accounts that cannot publish until reconnected. */
    attentionHandles?: string[];
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

type ShortcutEvent = Pick<
    KeyboardEvent,
    'altKey' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey'
>;

type SubmitGuard = {
    disabled?: boolean;
    uploading: boolean;
    attentionBlocked?: boolean;
    processing: boolean;
    trayMode: ScheduleTray['mode'];
    queueDisabled?: boolean;
};

export function isSubmitShortcut(event: ShortcutEvent): boolean {
    return (
        (event.metaKey || event.ctrlKey) &&
        !event.altKey &&
        !event.shiftKey &&
        event.key === 'Enter'
    );
}

export function shouldAllowSubmit({
    disabled,
    uploading,
    attentionBlocked,
    processing,
    trayMode,
    queueDisabled,
}: SubmitGuard): boolean {
    return !(
        disabled ||
        uploading ||
        attentionBlocked ||
        processing ||
        (trayMode === 'queue' && Boolean(queueDisabled))
    );
}

export function SubmitBar({
    tray,
    postId,
    disabled,
    uploading = false,
    attentionHandles = [],
    onSaveDraft,
    onEnsurePost,
    queueDisabled,
    onOptimisticSubmit,
    onServerPost,
}: Props) {
    // useHttp verbs take NO inline data — the body is injected via transform()
    // at submit time so it always reflects the latest reducer state.
    const http = useHttp<{ scheduled_at?: string | null }, { post: PostView }>(
        {},
    );
    const [noSlot, setNoSlot] = useState(false);
    const [pastTime, setPastTime] = useState(false);
    const attentionBlocked = attentionHandles.length > 0;

    const submitLabel =
        tray.mode === 'now'
            ? 'Publish now'
            : tray.mode === 'queue'
              ? 'Add to queue'
              : 'Schedule';

    async function handleSubmit() {
        if (
            !shouldAllowSubmit({
                disabled,
                uploading,
                attentionBlocked,
                processing: http.processing,
                trayMode: tray.mode,
                queueDisabled,
            })
        ) {
            return;
        }

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
        const handleSubmitException = (
            response: { status: number },
            revert: () => void,
        ) => {
            revert();
            if (response.status === 402) {
                router.visit(billingRoute().url);
            }
        };

        if (tray.mode === 'now') {
            // Flip the chips to "Publishing" instantly; revert if the call fails.
            const revert = onOptimisticSubmit(OPTIMISTIC_PUBLISH);
            http.transform(() => ({}));
            await http.post(publish(id).url, {
                onSuccess,
                onHttpException: (response) =>
                    handleSubmitException(response, revert),
                onNetworkError: revert,
            });

            return;
        }

        if (tray.mode === 'queue') {
            // Flip the chips to "Queued" instantly; revert if the call fails.
            const revert = onOptimisticSubmit(OPTIMISTIC_SCHEDULE);
            http.transform(() =>
                tray.pickedAt ? { scheduled_at: tray.pickedAt } : {},
            );
            await http.post(queue(id).url, {
                onSuccess,
                // 422 = no open slot in the workspace posting schedule.
                onHttpException: (response) => {
                    handleSubmitException(response, revert);
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
                handleSubmitException(response, revert);
                if (response.status === 422) {
                    setPastTime(true);
                }
            },
            onNetworkError: revert,
        });
    }

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (
                !isSubmitShortcut(event) ||
                !shouldAllowSubmit({
                    disabled,
                    uploading,
                    attentionBlocked,
                    processing: http.processing,
                    trayMode: tray.mode,
                    queueDisabled,
                })
            ) {
                return;
            }

            event.preventDefault();
            void handleSubmit();
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    });

    const canSubmit = shouldAllowSubmit({
        disabled,
        uploading,
        attentionBlocked,
        processing: http.processing,
        trayMode: tray.mode,
        queueDisabled,
    });

    const submitButton = (
        <TrayButton
            variant="primary"
            disabled={!canSubmit}
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
                        <TooltipTrigger
                            render={
                                <span
                                    tabIndex={0}
                                    className="flex-1 sm:flex-none"
                                />
                            }
                        >
                            {submitButton}
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

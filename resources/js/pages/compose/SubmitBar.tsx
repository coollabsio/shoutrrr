import { Link, useHttp } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import { cn } from '@/lib/utils';
import { publish, queue } from '@/routes/posts';

import type { ScheduleTray } from './composer-state';
import type { PostView } from './types';

type Props = {
    tray: ScheduleTray;
    postId: string | null;
    disabled?: boolean;
    /** Flush the autosave (called before scheduling and on Save draft). */
    onSaveDraft: () => void;
    /** Ensure a persisted post id before publishing; returns the post id. */
    onEnsurePost: () => Promise<string>;
    /** Adopt the server's post after a successful publish/queue/schedule. */
    onSubmitted?: (post: PostView) => void;
    /** When in queue mode, true if there is no slot to queue into (no schedule, full, loading, or error). */
    queueDisabled?: boolean;
};

export function SubmitBar({
    tray,
    postId,
    disabled,
    onSaveDraft,
    onEnsurePost,
    onSubmitted,
    queueDisabled,
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
        // Flush any pending edits, then issue the publish/queue/schedule call.
        onSaveDraft();
        const id = postId ?? (await onEnsurePost());
        if (!id) {
            return;
        }

        if (tray.mode === 'now') {
            http.transform(() => ({}));
            const result = await http.post(publish(id).url, {
                onNetworkError: () => undefined,
            });
            onSubmitted?.(result.post);

            return;
        }

        if (tray.mode === 'queue') {
            http.transform(() => ({}));
            await http.post(queue(id).url, {
                onSuccess: (data) => onSubmitted?.(data.post),
                // 422 = no open slot in the workspace posting schedule.
                onHttpException: (response) => {
                    if (response.status === 422) {
                        setNoSlot(true);
                    }
                },
                onNetworkError: () => undefined,
            });

            return;
        }

        // mode === 'pick' → schedule at the chosen time (existing M2 path).
        http.transform(() => ({ scheduled_at: tray.pickedAt }));
        await http.put(PostScheduleController.update(id).url, {
            onSuccess: (data) => onSubmitted?.(data.post),
            // 422 = the chosen time is in the past (server guard).
            onHttpException: (response) => {
                if (response.status === 422) {
                    setPastTime(true);
                }
            },
            onNetworkError: () => undefined,
        });
    }

    return (
        <div className="flex flex-col items-end gap-1.5 justify-self-end">
            <div className="flex items-center gap-1.5">
                <TrayButton onClick={onSaveDraft} disabled={disabled}>
                    Save draft
                </TrayButton>
                <TrayButton
                    variant="primary"
                    disabled={
                        disabled ||
                        http.processing ||
                        (tray.mode === 'queue' && Boolean(queueDisabled))
                    }
                    onClick={() => void handleSubmit()}
                >
                    <Send className="size-3.5" aria-hidden="true" />
                    <span>{submitLabel}</span>
                    <kbd className="ml-0.5 hidden h-4 items-center rounded border border-primary-foreground/25 bg-primary-foreground/15 px-1 font-mono text-[10px] leading-none font-normal text-primary-foreground/90 sm:inline-flex">
                        ⌘↵
                    </kbd>
                </TrayButton>
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
};

function TrayButton({
    children,
    variant = 'outline',
    disabled = false,
    onClick,
}: TrayButtonProps) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-[12.5px] font-medium transition-[background,border-color,transform] duration-[120ms] active:scale-[0.985]',
                variant === 'outline' &&
                    'border-border bg-background text-foreground hover:bg-muted disabled:opacity-50',
                variant === 'primary' &&
                    'border-primary bg-primary text-primary-foreground shadow-[0_1px_2px_0_rgb(0_0_0/0.04)] hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50',
            )}
        >
            {children}
        </button>
    );
}

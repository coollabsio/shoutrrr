import { useHttp } from '@inertiajs/react';
import { Send } from 'lucide-react';
import type { ReactNode } from 'react';

import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

import type { ScheduleTray } from './composer-state';
import type { PostView } from './types';

type Props = {
    tray: ScheduleTray;
    postId: string | null;
    disabled?: boolean;
    /** Flush the autosave (called before scheduling and on Save draft). */
    onSaveDraft: () => void;
    /** Ensure a persisted post id before scheduling; returns the post id. */
    onEnsurePost: () => Promise<string>;
    /** Adopt the server's post after a successful schedule. */
    onScheduled?: (post: PostView) => void;
};

export function SubmitBar({
    tray,
    postId,
    disabled,
    onSaveDraft,
    onEnsurePost,
    onScheduled,
}: Props) {
    // useHttp verbs take NO inline data — the body is injected via transform()
    // at submit time so it always reflects the latest reducer state.
    const http = useHttp<Record<string, never>, { post: PostView }>({});

    // Only mode `pick` actually publishes/schedules in M2.5; `now` and `queue`
    // arrive in M3 and keep the primary button disabled with a tooltip.
    const canPublish = tray.mode === 'pick';
    const submitLabel = tray.mode === 'now' ? 'Publish now' : 'Schedule';

    async function handleSubmit() {
        if (!canPublish) {
            return;
        }
        // Flush any pending edits, then persist scheduled_at.
        onSaveDraft();
        const id = postId ?? (await onEnsurePost());
        if (!id) {
            return;
        }
        http.transform(() => ({ scheduled_at: tray.pickedAt }));
        const result = await http.put(PostScheduleController.update(id).url, {
            onNetworkError: () => undefined,
        });
        onScheduled?.(result.post);
    }

    const submitButton = (
        <TrayButton
            variant="primary"
            disabled={disabled || !canPublish}
            onClick={() => void handleSubmit()}
        >
            <Send className="size-3.5" aria-hidden="true" />
            <span>{submitLabel}</span>
            <kbd className="ml-0.5 hidden h-4 items-center rounded border border-primary-foreground/25 bg-primary-foreground/15 px-1 font-mono text-[10px] leading-none font-normal text-primary-foreground/90 sm:inline-flex">
                ⌘↵
            </kbd>
        </TrayButton>
    );

    return (
        <div className="flex items-center gap-1.5 justify-self-end">
            <TrayButton onClick={onSaveDraft} disabled={disabled}>
                Save draft
            </TrayButton>
            {canPublish ? (
                submitButton
            ) : (
                <Tooltip>
                    {/* A disabled button swallows pointer events, so the span
                        carries the hover/focus that opens the tooltip. */}
                    <TooltipTrigger asChild>
                        {/* oxlint-disable-next-line no-noninteractive-tabindex -- intentional: the span must be focusable so a disabled button still surfaces its tooltip */}
                        <span tabIndex={0} className="inline-flex">
                            {submitButton}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>Publishing arrives in M3</TooltipContent>
                </Tooltip>
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

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
import { type AccountBlock, describeReason } from '@/lib/compose/precheck';
import {
    OPTIMISTIC_PUBLISH,
    OPTIMISTIC_SCHEDULE,
    type OptimisticSubmit,
} from '@/lib/compose/publish-status';
import { cn } from '@/lib/utils';
import { index as billingRoute } from '@/routes/billing';
import { publish, queue } from '@/routes/posts';
import type { PlatformLimits, PlatformName, PostView } from '@/types/compose';

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
    /** Accounts whose content will be rejected by the platform (live). */
    blockedAccounts: AccountBlock[];
    /** Per-platform limits, for rendering block reasons. */
    limits: PlatformLimits[];
};

export function hasBlockingIssues(blocked: AccountBlock[]): boolean {
    return blocked.length > 0;
}

function limitsFor(
    limits: PlatformLimits[],
    platform: PlatformName,
): PlatformLimits {
    return (
        limits.find((item) => item.platform === platform) ?? {
            platform,
            maxLength: 0,
            maxBytes: null,
            maxMedia: 0,
            maxMediaBytes: 0,
            allowedMime: [],
            threadMax: null,
            maxImageDimensions: { width: 0, height: 0 },
            allowedVideoMime: [],
            maxVideoBytes: 0,
            maxVideoDurationSeconds: 0,
        }
    );
}

// onHttpException's response.data is typed `string` but may arrive already
// parsed at runtime — handle both, mirroring the pattern in
// resources/js/hooks/compose/use-autosave.ts.
function parseServerBlocked(raw: unknown): AccountBlock[] {
    let data: unknown = raw;
    if (typeof raw === 'string') {
        try {
            data = JSON.parse(raw);
        } catch {
            return [];
        }
    }
    if (typeof data !== 'object' || data === null || !('blocked' in data)) {
        return [];
    }
    const blocked = (data as { blocked?: unknown }).blocked;
    if (!Array.isArray(blocked)) {
        return [];
    }

    return blocked.map((item) => ({
        accountId: String(
            (item as { connected_account_id?: string }).connected_account_id ??
                '',
        ),
        handle: String((item as { handle?: string }).handle ?? ''),
        platform: (item as { platform?: unknown }).platform as PlatformName,
        reasons:
            ((item as { issues?: unknown })
                .issues as AccountBlock['reasons']) ?? [],
    }));
}

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
    blockedAccounts,
    limits,
}: Props) {
    // useHttp verbs take NO inline data — the body is injected via transform()
    // at submit time so it always reflects the latest reducer state.
    const http = useHttp<{ scheduled_at?: string | null }, { post: PostView }>(
        {},
    );
    const [noSlot, setNoSlot] = useState(false);
    const [pastTime, setPastTime] = useState(false);
    const attentionBlocked = attentionHandles.length > 0;
    // Server-reported blocks (belt-and-suspenders for edge cases the client
    // pre-check missed). Keyed identically to client AccountBlock.
    const [serverBlocked, setServerBlocked] = useState<AccountBlock[]>([]);
    // Only reveal the block list after a submit attempt, so it doesn't nag before.
    const [showBlocked, setShowBlocked] = useState(false);

    // Prefer live client blocks; fall back to the last server response.
    const blocks = blockedAccounts.length > 0 ? blockedAccounts : serverBlocked;

    const submitLabel =
        tray.mode === 'now'
            ? 'Publish now'
            : tray.mode === 'queue'
              ? 'Add to queue'
              : 'Schedule';

    async function handleSubmit() {
        if (hasBlockingIssues(blockedAccounts)) {
            setShowBlocked(true);
            setServerBlocked([]);

            return;
        }

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
            response: { status: number; data?: unknown },
            revert: () => void,
        ) => {
            revert();
            if (response.status === 402) {
                router.visit(billingRoute().url);

                return;
            }
            if (response.status === 422) {
                const blocked = parseServerBlocked(response.data);
                if (blocked.length > 0) {
                    setServerBlocked(blocked);
                    setShowBlocked(true);
                }
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
            {showBlocked && blocks.length > 0 && (
                <ul className="space-y-0.5 text-[12px] text-destructive">
                    {blocks.map((block) => (
                        <li key={block.accountId}>
                            <span className="font-medium">{block.handle}</span>
                            {' — '}
                            {block.reasons
                                .map((reason) =>
                                    describeReason(
                                        reason,
                                        block.platform,
                                        limitsFor(limits, block.platform),
                                    ),
                                )
                                .join('; ')}
                        </li>
                    ))}
                </ul>
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

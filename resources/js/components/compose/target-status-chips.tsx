import { Check, ExternalLink, RotateCw, X } from 'lucide-react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    type TargetTone,
    targetStatusMeta,
} from '@/lib/compose/publish-status';
import { platformLabel, postPermalink } from '@/lib/posts/permalink';
import { cn } from '@/lib/utils';
import type { TargetView } from '@/types/compose';

const TONE_CLASS: Record<TargetTone, string> = {
    pending: 'bg-muted text-muted-foreground',
    active: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-500',
    error: 'bg-destructive/10 text-destructive',
    muted: 'bg-muted text-muted-foreground',
};

/**
 * The subset of {@link TargetView} the chips actually render. The posts INDEX
 * payload sends only these per-target fields, so a full `TargetView` is not
 * required here — full views (from the composer) satisfy this structurally.
 */
export type ChipTarget = Pick<
    TargetView,
    'id' | 'platform' | 'status' | 'error_message' | 'attempts'
> &
    Partial<Pick<TargetView, 'handle' | 'display_name' | 'remote_id'>>;

type Props = {
    targets: ChipTarget[];
    /** Retry a single failed target; omit to hide the Retry control. */
    onRetry?: (targetId: string) => void;
    /** Target ids with an in-flight retry request (disables their Retry button). */
    retryingIds?: ReadonlySet<string>;
};

/**
 * Live per-target publish status: a platform glyph, a tinted status label
 * (spinner while publishing, check when published, ✕ + error message when
 * failed), and an inline Retry on failed targets.
 */
export function TargetStatusChips({ targets, onRetry, retryingIds }: Props) {
    if (targets.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-1.5">
            {targets.map((target) => {
                const meta = targetStatusMeta(target.status);
                const isFailed = target.status === 'failed';
                const isRetrying = retryingIds?.has(target.id) ?? false;
                const attempts = target.attempts ?? 0;
                const errorMessage = target.error_message
                    ? attempts > 0
                        ? `Attempt ${attempts}: ${target.error_message}`
                        : target.error_message
                    : null;
                const permalink =
                    target.status === 'published'
                        ? postPermalink(
                              target.platform,
                              target.handle,
                              target.remote_id,
                          )
                        : null;

                return (
                    <li
                        key={target.id}
                        className="flex items-center gap-2 text-[12px]"
                    >
                        <span
                            aria-hidden="true"
                            className="grid size-[18px] shrink-0 place-items-center rounded-[5px] bg-muted text-foreground"
                        >
                            <PlatformGlyph
                                platform={target.platform}
                                size={10}
                            />
                        </span>
                        <span className="truncate text-muted-foreground">
                            {target.handle ??
                                target.display_name ??
                                target.platform}
                        </span>
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium',
                                TONE_CLASS[meta.tone],
                            )}
                        >
                            {meta.spinning ? (
                                <Spinner className="size-3" />
                            ) : target.status === 'published' ? (
                                <Check className="size-3" aria-hidden="true" />
                            ) : isFailed ? (
                                <X className="size-3" aria-hidden="true" />
                            ) : null}
                            <span>{meta.label}</span>
                        </span>
                        {isFailed && errorMessage && (
                            <span className="min-w-0 flex-1 truncate text-destructive/90">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span
                                            tabIndex={0}
                                            className="inline-block max-w-full cursor-help truncate underline decoration-destructive/40 decoration-dotted underline-offset-2"
                                        >
                                            {errorMessage}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        side="top"
                                        align="start"
                                        className="block max-w-80 border border-border text-left leading-relaxed whitespace-normal text-popover-foreground shadow-lg [--tooltip-bg:var(--popover)]"
                                    >
                                        {attempts > 0 && (
                                            <span className="font-medium whitespace-nowrap">
                                                Attempt {attempts}:{' '}
                                            </span>
                                        )}
                                        <span>{target.error_message}</span>
                                    </TooltipContent>
                                </Tooltip>
                            </span>
                        )}
                        {isFailed && onRetry && (
                            <button
                                type="button"
                                disabled={isRetrying}
                                onClick={() => onRetry(target.id)}
                                className="ml-auto inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-border bg-background px-2 text-[11.5px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                            >
                                <RotateCw
                                    className={cn(
                                        'size-3',
                                        isRetrying && 'animate-spin',
                                    )}
                                    aria-hidden="true"
                                />
                                Retry
                            </button>
                        )}
                        {permalink && (
                            <a
                                href={permalink}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="ml-auto inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-border bg-background px-2 text-[11.5px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            >
                                View on {platformLabel(target.platform)}
                                <ExternalLink
                                    className="size-3"
                                    aria-hidden="true"
                                />
                            </a>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

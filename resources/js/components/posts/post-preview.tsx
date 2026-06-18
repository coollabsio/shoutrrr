import { PlatformGlyph } from '@/components/common/platform-glyph';
import { cn } from '@/lib/utils';
import type { PublicMedia, PublicPostView, PublicTarget } from '@/types/share';

// Platform labels for display (no shared utility exists yet).
const PLATFORM_LABEL: Record<string, string> = {
    x: 'X (Twitter)',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
};

function platformLabel(platform: string): string {
    return PLATFORM_LABEL[platform] ?? platform;
}

// Status chip tones — mirrors post-row.tsx STATUS_META using utility classes
// (no --success/--warning/--info CSS tokens in this project).
const STATUS_CHIP: Record<string, { label: string; className: string }> = {
    published: {
        label: 'Published',
        className:
            'bg-emerald-500/10 text-emerald-600 dark:text-emerald-500 ring-emerald-500/20',
    },
    partial: {
        label: 'Partly out',
        className:
            'bg-amber-500/10 text-amber-600 dark:text-amber-500 ring-amber-500/20',
    },
    failed: {
        label: 'Failed',
        className: 'bg-destructive/10 text-destructive ring-destructive/20',
    },
    scheduled: {
        label: 'Scheduled',
        className:
            'bg-blue-500/10 text-blue-600 dark:text-blue-400 ring-blue-500/20',
    },
    publishing: {
        label: 'Publishing',
        className:
            'bg-blue-500/10 text-blue-600 dark:text-blue-400 ring-blue-500/20',
    },
    draft: {
        label: 'Draft',
        className: 'bg-muted text-muted-foreground ring-border',
    },
    missed: {
        label: 'Missed',
        className:
            'bg-slate-500/10 text-slate-600 dark:text-slate-400 ring-slate-500/20',
    },
};

export function StatusChip({ status }: { status: string | null }) {
    // 'pending' is resting state of an unpublished target — not meaningful to viewers.
    if (!status || status === 'pending') {
        return null;
    }
    const meta = STATUS_CHIP[status] ?? {
        label: status,
        className: 'bg-muted text-muted-foreground ring-border',
    };
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium capitalize ring-1 ring-inset',
                meta.className,
            )}
        >
            <span className="size-1.5 rounded-full bg-current" />
            {meta.label}
        </span>
    );
}

function MediaGallery({ media }: { media: PublicMedia[] }) {
    if (media.length === 0) {
        return null;
    }
    return (
        <div
            className="animate-in duration-700 fill-mode-both fade-in slide-in-from-bottom-3"
            style={{ animationDelay: '80ms' }}
        >
            <div
                className={cn(
                    'grid gap-2.5',
                    media.length === 1
                        ? 'grid-cols-1'
                        : 'grid-cols-2 sm:grid-cols-3',
                )}
            >
                {media.map((m) => (
                    <a
                        key={m.id}
                        href={m.url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="group relative block overflow-hidden rounded-2xl border border-border bg-muted/40 ring-1 ring-black/5"
                    >
                        <img
                            src={m.url}
                            alt={m.alt_text ?? ''}
                            loading="lazy"
                            className={cn(
                                'w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]',
                                media.length === 1
                                    ? 'max-h-[460px]'
                                    : 'aspect-square',
                            )}
                        />
                    </a>
                ))}
            </div>
        </div>
    );
}

/** Account avatar with a platform-glyph corner badge; falls back to an initial. */
function AccountAvatar({ target }: { target: PublicTarget }) {
    const label = target.display_name ?? target.handle ?? target.platform;
    const initial = (label.replace(/^@/, '')[0] ?? '?').toUpperCase();
    return (
        <span className="relative inline-block shrink-0">
            {target.avatar_url ? (
                <img
                    src={target.avatar_url}
                    alt=""
                    loading="lazy"
                    className="size-10 rounded-full object-cover ring-1 ring-border"
                />
            ) : (
                <span className="grid size-10 place-items-center rounded-full bg-muted text-[15px] font-semibold text-muted-foreground ring-1 ring-border">
                    {initial}
                </span>
            )}
            <span className="absolute -right-0.5 -bottom-0.5 grid size-[18px] place-items-center rounded-full bg-card text-foreground ring-2 ring-card">
                <span className="grid size-[18px] place-items-center rounded-full bg-muted">
                    <PlatformGlyph platform={target.platform} size={9} />
                </span>
            </span>
        </span>
    );
}

function PlatformCard({
    target,
    index,
}: {
    target: PublicTarget;
    index: number;
}) {
    const displayLabel =
        target.display_name ??
        (target.handle ? target.handle : target.platform);
    const handleLine = target.handle ? target.handle : null;
    const showHandle = handleLine && handleLine !== displayLabel;
    return (
        <article
            className="animate-in rounded-3xl border border-border bg-card/80 p-5 shadow-sm ring-1 ring-black/[0.02] backdrop-blur-sm duration-700 fill-mode-both fade-in slide-in-from-bottom-3 sm:p-6"
            style={{ animationDelay: `${160 + index * 90}ms` }}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <AccountAvatar target={target} />
                    <div className="min-w-0 leading-tight">
                        <p className="truncate text-[14px] font-semibold tracking-tight text-foreground">
                            {displayLabel}
                        </p>
                        <p className="truncate text-[11.5px] text-muted-foreground">
                            {showHandle ? (
                                <>
                                    {handleLine}{' '}
                                    <span className="text-muted-foreground/50">
                                        ·
                                    </span>{' '}
                                </>
                            ) : null}
                            {platformLabel(target.platform)}
                        </p>
                    </div>
                </div>
                <StatusChip status={target.status} />
            </div>

            <div className="mt-4 space-y-2.5">
                {target.sections.map((section, i) => (
                    <div
                        key={`${target.platform}-${target.handle ?? index}-${i}`}
                        className="relative"
                    >
                        {target.sections.length > 1 && (
                            <span className="absolute top-1 -left-px text-[10px] font-semibold text-muted-foreground/60 tabular-nums">
                                {i + 1}/{target.sections.length}
                            </span>
                        )}
                        <div
                            className={cn(
                                'rounded-2xl bg-background/60 px-4 py-3 ring-1 ring-border ring-inset',
                                target.sections.length > 1 && 'ml-7',
                            )}
                        >
                            <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap text-foreground">
                                {section || (
                                    <span className="text-muted-foreground italic">
                                        No content.
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </article>
    );
}

/**
 * Polished, platform-styled rendering of a post's per-account content.
 * Consumes {@link PublicPostView} directly — no intermediate PostContent mapper.
 */
export function PostPreview({
    post,
    className,
}: {
    post: PublicPostView;
    className?: string;
}) {
    return (
        <div className={cn('space-y-4', className)}>
            <MediaGallery media={post.media} />
            {post.targets.map((t, i) => (
                <PlatformCard
                    key={`${t.platform}-${t.handle ?? i}`}
                    target={t}
                    index={i}
                />
            ))}
            {post.targets.length === 0 && (
                <div className="rounded-3xl border border-dashed border-border bg-card/60 px-6 py-10 text-center">
                    <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap text-foreground">
                        {post.base_text || (
                            <span className="text-muted-foreground italic">
                                No content.
                            </span>
                        )}
                    </p>
                </div>
            )}
        </div>
    );
}

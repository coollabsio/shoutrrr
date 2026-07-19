import {
    BarChart3,
    CheckCircle2,
    Eye,
    Heart,
    MessageCircle,
    Repeat2,
} from 'lucide-react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type {
    PlatformPreview,
    PlatformPreviewItem,
} from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

import { FacebookPreview } from './preview/facebook-preview';
import { InstagramPreview } from './preview/instagram-preview';

const PLATFORM_LABELS: Record<PlatformName, string> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
    facebook: 'Facebook',
    instagram: 'Instagram',
    threads: 'Threads',
    discord: 'Discord',
};

const PLATFORM_GLYPH_CLASS: Record<PlatformName, string> = {
    x: 'text-foreground',
    bluesky: 'text-sky-500',
    linkedin: 'text-blue-600',
    facebook: 'text-[#1877F2]',
    instagram: 'text-[#E4405F]',
    threads: 'text-foreground',
    discord: 'text-[#5865F2]',
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function platformActions(platform: PlatformName): string {
    if (platform === 'linkedin') {
        return 'Comment · Repost · Like · Analytics';
    }
    if (platform === 'bluesky') {
        return 'Reply · Repost · Like';
    }

    return 'Reply · Repost · Like · Views';
}

function previewSummary(preview: PlatformPreview): string {
    const label = PLATFORM_LABELS[preview.platform];

    if (preview.platform === 'linkedin') {
        return 'Posted as one LinkedIn update.';
    }

    if (preview.autoSplit && preview.items.length > 1) {
        return `Auto-split into a ${preview.items.length}-post ${label} thread.`;
    }

    return `Posted as one ${label} update unless you add more split markers.`;
}

function PlatformPreviewMedia({ item }: { item: PlatformPreviewItem }) {
    if (item.media.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'mt-3 grid overflow-hidden rounded-2xl border border-border bg-muted/40',
                item.media.length === 1 ? 'grid-cols-1' : 'grid-cols-2',
            )}
        >
            {item.media.slice(0, 4).map((media) => (
                <div
                    key={media.id}
                    className="relative aspect-video overflow-hidden bg-muted"
                >
                    {media.kind === 'video' ? (
                        <video
                            src={media.url}
                            className="size-full object-cover"
                            muted
                        />
                    ) : (
                        <img
                            src={media.url}
                            alt={media.alt_text ?? ''}
                            className="size-full object-cover"
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function PlatformPreviewPost({
    preview,
    item,
    isLast,
}: {
    preview: PlatformPreview;
    item: PlatformPreviewItem;
    isLast: boolean;
}) {
    const label = PLATFORM_LABELS[preview.platform];

    return (
        <article className="relative grid grid-cols-[40px_minmax(0,1fr)] gap-3">
            {!isLast && preview.platform !== 'linkedin' && (
                <div
                    className="absolute top-11 bottom-0 left-5 w-px bg-border"
                    aria-hidden
                />
            )}
            <Avatar className="z-10 size-10 bg-background">
                <AvatarImage src={preview.avatarUrl ?? undefined} />
                <AvatarFallback className="text-[11px] font-semibold">
                    {initials(preview.accountName)}
                </AvatarFallback>
            </Avatar>
            <div className="min-w-0 pb-5">
                <div className="flex min-w-0 items-center gap-1.5 text-[13px] leading-5">
                    <span className="truncate font-semibold text-foreground">
                        {preview.accountName}
                    </span>
                    {preview.platform !== 'linkedin' && (
                        <CheckCircle2
                            className="size-3.5 shrink-0 fill-sky-500 text-background"
                            aria-hidden
                        />
                    )}
                    <span className="truncate text-muted-foreground">
                        {preview.accountHandle} · now
                    </span>
                </div>
                <p
                    className={cn(
                        'mt-0.5 text-[14px] leading-5 wrap-anywhere whitespace-pre-wrap text-foreground',
                        item.overLimit && 'text-destructive',
                    )}
                >
                    <LinkedText
                        text={item.text}
                        platform={preview.platform}
                        linkExclusions={item.linkExclusions}
                        emptyFallback={`Start writing to preview your ${label} post.`}
                    />
                </p>
                <PlatformPreviewMedia item={item} />
                <div className="mt-3 flex items-center gap-4 text-muted-foreground">
                    <span className="inline-flex items-center gap-1 text-[12px]">
                        <MessageCircle className="size-3.5" />
                        Preview
                    </span>
                    <Repeat2 className="size-3.5" aria-hidden />
                    <Heart className="size-3.5" aria-hidden />
                    {preview.platform !== 'bluesky' && (
                        <BarChart3 className="size-3.5" aria-hidden />
                    )}
                    <span className="sr-only">
                        {platformActions(preview.platform)}
                    </span>
                </div>
                {item.overLimit && (
                    <p className="mt-2 text-[12px] font-medium text-destructive">
                        {item.count}/{preview.limit} characters
                    </p>
                )}
            </div>
        </article>
    );
}

export function PlatformPreviewPanel({
    preview,
}: {
    preview: PlatformPreview | null;
}) {
    const label = preview ? PLATFORM_LABELS[preview.platform] : null;

    return (
        <aside className="rounded-xl border bg-card text-card-foreground shadow-sm">
            {/* h-[45px] matches the composer's top bar so the two cards' headers
            line up when shown side by side. */}
            <div className="flex h-[45px] items-center gap-2 border-b border-border px-4">
                {preview ? (
                    <span
                        className={cn(
                            'grid size-5 place-items-center rounded-md border bg-background',
                            PLATFORM_GLYPH_CLASS[preview.platform],
                        )}
                    >
                        <PlatformGlyph platform={preview.platform} size={11} />
                    </span>
                ) : (
                    <span className="grid size-5 place-items-center rounded-md border bg-background text-muted-foreground">
                        <Eye className="size-3" />
                    </span>
                )}
                <div className="min-w-0">
                    <h2 className="truncate text-[13px] leading-tight font-semibold tracking-tight">
                        {preview ? `How it lands on ${label}` : 'Post preview'}
                    </h2>
                    <p className="truncate text-[12px] leading-tight text-muted-foreground">
                        {preview
                            ? 'Live preview from the current draft'
                            : 'See how your draft lands on each platform'}
                    </p>
                </div>
            </div>

            {!preview ? (
                <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <span className="grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                        <Eye className="size-4" />
                    </span>
                    <div className="space-y-1">
                        <p className="text-[13px] font-medium text-foreground">
                            Nothing to preview yet
                        </p>
                        <p className="text-[12px] leading-5 text-muted-foreground">
                            Connect an account to see how your post will appear.
                        </p>
                    </div>
                </div>
            ) : preview.platform === 'instagram' ? (
                <InstagramPreview preview={preview} />
            ) : preview.platform === 'facebook' ? (
                <FacebookPreview preview={preview} />
            ) : (
                <div className="p-4">
                    <div className="rounded-3xl border border-border bg-background px-4 pt-4 shadow-xs">
                        {preview.items.map((item, index) => (
                            <PlatformPreviewPost
                                key={item.id}
                                preview={preview}
                                item={item}
                                isLast={index === preview.items.length - 1}
                            />
                        ))}
                    </div>
                    <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
                        {previewSummary(preview)}
                    </p>
                </div>
            )}
        </aside>
    );
}

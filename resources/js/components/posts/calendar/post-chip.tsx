import { useDraggable } from '@dnd-kit/core';
import { router } from '@inertiajs/react';
import type { CSSProperties, MouseEvent } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import { PlatformGlyphStack } from '@/components/common/platform-glyph-stack';
import type { PostRowData } from '@/components/posts/post-row';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import { toUserTz } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';

type Tone = 'scheduled' | 'published' | 'failed';

function toneOf(status: PostRowData['status']): Tone {
    if (status === 'failed' || status === 'partial') return 'failed';
    if (status === 'published') return 'published';
    return 'scheduled';
}

const toneStyles: Record<
    Tone,
    { chip: string; strip: string; pulse: boolean }
> = {
    scheduled: {
        chip: 'bg-sky-500/10 text-sky-700 hover:bg-sky-500/15 dark:bg-sky-400/15 dark:text-sky-200 dark:hover:bg-sky-400/20',
        strip: 'bg-sky-500 dark:bg-sky-400',
        pulse: false,
    },
    published: {
        chip: 'bg-muted text-muted-foreground hover:bg-muted/80',
        strip: 'bg-muted-foreground/40',
        pulse: false,
    },
    failed: {
        chip: 'bg-destructive/10 text-destructive hover:bg-destructive/15',
        strip: 'bg-destructive',
        pulse: true,
    },
};

export function PostChip({
    post,
    draggable,
}: {
    post: PostRowData;
    draggable: boolean;
}) {
    const { attributes, listeners, setNodeRef, transform, isDragging } =
        useDraggable({
            id: `post-${post.id}`,
            data: { postId: post.id, scheduledAt: post.scheduled_at },
            disabled: !draggable,
        });

    const tz = useSchedulingTimezone();
    const when = post.scheduled_at
        ? toUserTz(post.scheduled_at, tz).format('h:mma')
        : '';
    const tone = toneStyles[toneOf(post.status)];
    const targetCount = post.target_count ?? 0;
    const label = post.base_text || 'Untitled';
    const title =
        targetCount > 1 ? `${label} — ${targetCount} accounts` : label;

    const style: CSSProperties = transform
        ? {
              transform: `translate3d(${transform.x}px, ${transform.y}px, 0)`,
              zIndex: 30,
          }
        : {};

    function openPost(e: MouseEvent) {
        // A chip click must never bubble to the day cell's create-post handler,
        // including the synthetic click dnd fires at the end of a drag.
        e.stopPropagation();
        if (isDragging) {
            return;
        }
        router.visit(ComposerController.show(post.id).url);
    }

    return (
        <div
            ref={setNodeRef}
            style={style}
            {...(draggable ? listeners : {})}
            {...attributes}
            onClick={openPost}
            className={cn(
                'group/chip relative flex h-5 items-center gap-1.5 truncate rounded-sm pr-1.5 pl-2 text-[10.5px] font-medium tabular-nums transition-colors',
                'focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none',
                tone.chip,
                tone.pulse && 'animate-pulse',
                draggable
                    ? 'cursor-grab active:cursor-grabbing'
                    : 'cursor-pointer',
                isDragging && 'opacity-50',
            )}
            title={title}
        >
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0.5 left-0 w-[2px] rounded-full',
                    tone.strip,
                )}
            />
            <PlatformGlyphStack
                platforms={post.platforms ?? []}
                targetCount={targetCount}
                size={10}
                className="shrink-0 opacity-80"
            />
            {when && <span className="shrink-0 opacity-75">{when}</span>}
            <span className="truncate">{label}</span>
        </div>
    );
}

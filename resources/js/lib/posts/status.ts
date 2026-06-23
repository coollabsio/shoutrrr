import type { VariantProps } from 'class-variance-authority';

import type { badgeVariants } from '@/components/ui/badge';
import type { PostStatus } from '@/types/compose';

type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>['variant']>;

/**
 * Single source of truth for how a post status renders: its display label and
 * the semantic Badge variant. Consumed by the posts list, post preview, and
 * calendar chips so status styling stays consistent everywhere.
 */
export const postStatusMeta: Record<
    PostStatus,
    { variant: BadgeVariant; label: string }
> = {
    draft: { variant: 'secondary', label: 'Draft' },
    scheduled: { variant: 'info', label: 'Scheduled' },
    publishing: { variant: 'info', label: 'Publishing' },
    published: { variant: 'success', label: 'Published' },
    partial: { variant: 'warning', label: 'Partial' },
    failed: { variant: 'destructive', label: 'Failed' },
    missed: { variant: 'warning', label: 'Missed' },
    deleted: { variant: 'secondary', label: 'Deleted' },
};

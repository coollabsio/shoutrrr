import type { PostView, TargetStatus, TargetView } from './types';

/** Per-target lifecycle states that are still in motion (poll while present). */
const ACTIVE_TARGET_STATUSES: ReadonlySet<TargetStatus> = new Set<TargetStatus>(
    ['pending', 'publishing', 'deleting'],
);

export type TargetTone = 'pending' | 'active' | 'success' | 'error' | 'muted';

export type TargetStatusMeta = {
    tone: TargetTone;
    label: string;
    /** Whether to render a spinning indicator. */
    spinning: boolean;
};

const TARGET_STATUS_META: Record<TargetStatus, TargetStatusMeta> = {
    pending: { tone: 'pending', label: 'Queued', spinning: false },
    publishing: { tone: 'active', label: 'Publishing', spinning: true },
    published: { tone: 'success', label: 'Published', spinning: false },
    failed: { tone: 'error', label: 'Failed', spinning: false },
    deleting: { tone: 'active', label: 'Deleting', spinning: true },
    deleted: { tone: 'muted', label: 'Deleted', spinning: false },
};

export function targetStatusMeta(status: TargetStatus): TargetStatusMeta {
    return TARGET_STATUS_META[status] ?? TARGET_STATUS_META.pending;
}

/** True while any target is still pending/publishing/deleting (drives polling). */
export function anyTargetActive(targets: TargetView[]): boolean {
    return targets.some((t) => ACTIVE_TARGET_STATUSES.has(t.status));
}

/** A post is terminal once no target is active (publishing finished, success or fail). */
export function isPostTerminal(post: PostView): boolean {
    return !anyTargetActive(post.targets);
}

/** Targets currently in the `failed` state (offer Retry). */
export function failedTargets(targets: TargetView[]): TargetView[] {
    return targets.filter((t) => t.status === 'failed');
}

import type {
    PostStatus,
    PostView,
    TargetStatus,
    TargetView,
} from '@/types/compose';

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
    skipped: { tone: 'muted', label: 'Skipped', spinning: false },
    deleting: { tone: 'active', label: 'Deleting', spinning: true },
    deleted: { tone: 'muted', label: 'Deleted', spinning: false },
};

export function targetStatusMeta(status: TargetStatus): TargetStatusMeta {
    return TARGET_STATUS_META[status] ?? TARGET_STATUS_META.pending;
}

/** True while any target is still pending/publishing/deleting. */
export function anyTargetActive(targets: TargetView[]): boolean {
    return targets.some((t) => ACTIVE_TARGET_STATUSES.has(t.status));
}

/** True when the composer page needs live status refreshes. */
export function shouldPollPostStatus(post: PostView): boolean {
    return post.targets.some(
        (target) =>
            target.status === 'publishing' ||
            target.status === 'deleting' ||
            (post.status === 'publishing' && target.status === 'pending'),
    );
}

/** A post is terminal once no target is active (publishing finished, success or fail). */
export function isPostTerminal(post: PostView): boolean {
    return !anyTargetActive(post.targets);
}

/** Targets currently in the `failed` state (offer Retry). */
export function failedTargets(targets: TargetView[]): TargetView[] {
    return targets.filter((t) => t.status === 'failed');
}

/**
 * Terminal target states that an optimistic submit must NOT disturb. These
 * mirror {@link PublishDispatcher::TERMINAL} on the server: already-published or
 * being-deleted/deleted targets are skipped when (re)dispatching, so optimistic
 * feedback should leave them as-is rather than flip them back to in-flight.
 */
const OPTIMISTIC_SKIP_STATUSES: ReadonlySet<TargetStatus> =
    new Set<TargetStatus>(['published', 'skipped', 'deleting', 'deleted']);

/**
 * The instant in-flight snapshot to show when the user hits Publish/Schedule,
 * before the server responds. `Publish now` drives targets to `publishing`
 * (spinner); `queue`/`pick` (schedule) drives them to `pending` (Queued). The
 * post status flips to a non-draft so the live status chips reveal immediately.
 */
export type OptimisticSubmit = {
    postStatus: PostStatus;
    targetStatus: Extract<TargetStatus, 'publishing' | 'pending'>;
};

export const OPTIMISTIC_PUBLISH: OptimisticSubmit = {
    postStatus: 'publishing',
    targetStatus: 'publishing',
};

export const OPTIMISTIC_SCHEDULE: OptimisticSubmit = {
    postStatus: 'scheduled',
    targetStatus: 'pending',
};

/**
 * Produce an optimistic `PostView` for an in-flight publish/schedule submit:
 * the post status flips per {@link OptimisticSubmit} and every non-terminal
 * target moves to the in-flight target status, clearing any prior error so a
 * fresh attempt doesn't show a stale failure. Terminal targets (published /
 * deleting / deleted) are left untouched. Pure — never mutates its input.
 */
export function applyOptimisticSubmit(
    post: PostView,
    optimistic: OptimisticSubmit,
): PostView {
    return {
        ...post,
        status: optimistic.postStatus,
        targets: post.targets.map((target) =>
            OPTIMISTIC_SKIP_STATUSES.has(target.status)
                ? target
                : {
                      ...target,
                      status: optimistic.targetStatus,
                      error_kind: null,
                      error_message: null,
                  },
        ),
    };
}

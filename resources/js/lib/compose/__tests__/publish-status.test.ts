import { describe, expect, it } from 'vitest';

import type { PostView, TargetStatus, TargetView } from '@/types/compose';

import {
    anyTargetActive,
    applyOptimisticSubmit,
    failedTargets,
    isPostTerminal,
    OPTIMISTIC_PUBLISH,
    OPTIMISTIC_SCHEDULE,
    targetStatusMeta,
} from '../publish-status';

function target(id: string, status: TargetStatus): TargetView {
    return {
        id,
        connected_account_id: `acc-${id}`,
        platform: 'x',
        handle: '@h',
        display_name: null,
        avatar_url: null,
        sections: ['x'],
        content_override: null,
        auto_split: true,
        issues: [],
        status,
        error_kind: null,
        error_message: null,
        attempts: 0,
        remote_id: null,
    };
}

function post(targets: TargetView[]): PostView {
    return {
        id: 'p1',
        base_text: 'hi',
        segments: ['hi'],
        status: 'publishing',
        published_at: null,
        updated_at: '2026-06-12T10:00:00+00:00',
        scheduled_at: null,
        destination: { kind: 'all', id: null },
        targets,
        media: [],
    };
}

describe('anyTargetActive', () => {
    it('is true while a target is pending or publishing', () => {
        expect(anyTargetActive([target('a', 'pending')])).toBe(true);
        expect(anyTargetActive([target('a', 'publishing')])).toBe(true);
        expect(anyTargetActive([target('a', 'deleting')])).toBe(true);
    });

    it('is false when all targets are terminal', () => {
        expect(
            anyTargetActive([target('a', 'published'), target('b', 'failed')]),
        ).toBe(false);
        expect(anyTargetActive([])).toBe(false);
    });
});

describe('isPostTerminal', () => {
    it('mirrors anyTargetActive inverted', () => {
        expect(isPostTerminal(post([target('a', 'publishing')]))).toBe(false);
        expect(
            isPostTerminal(
                post([target('a', 'published'), target('b', 'failed')]),
            ),
        ).toBe(true);
    });
});

describe('failedTargets', () => {
    it('returns only failed targets', () => {
        const ts = [
            target('a', 'published'),
            target('b', 'failed'),
            target('c', 'failed'),
        ];
        expect(failedTargets(ts).map((t) => t.id)).toEqual(['b', 'c']);
    });
});

describe('applyOptimisticSubmit', () => {
    it('flips a draft post and pending targets to publishing on Publish now', () => {
        const before = post([target('a', 'pending'), target('b', 'pending')]);
        before.status = 'draft';

        const after = applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(after.status).toBe('publishing');
        expect(after.targets.map((t) => t.status)).toEqual([
            'publishing',
            'publishing',
        ]);
    });

    it('flips to scheduled/pending on queue or schedule', () => {
        const before = post([target('a', 'failed')]);
        before.status = 'draft';

        const after = applyOptimisticSubmit(before, OPTIMISTIC_SCHEDULE);

        expect(after.status).toBe('scheduled');
        expect(after.targets[0].status).toBe('pending');
    });

    it('clears a prior error so a fresh attempt loses the stale failure', () => {
        const before = post([target('a', 'failed')]);
        before.targets[0].error_kind = 'rate_limited';
        before.targets[0].error_message = 'Too many requests';

        const after = applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(after.targets[0].error_kind).toBeNull();
        expect(after.targets[0].error_message).toBeNull();
    });

    it('leaves terminal targets (published/deleting/deleted) untouched', () => {
        const before = post([
            target('a', 'published'),
            target('b', 'deleting'),
            target('c', 'deleted'),
            target('d', 'pending'),
        ]);

        const after = applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(after.targets.map((t) => t.status)).toEqual([
            'published',
            'deleting',
            'deleted',
            'publishing',
        ]);
    });

    it('does not mutate the input (revert is just restoring the prior view)', () => {
        const before = post([target('a', 'pending')]);
        const snapshot = structuredClone(before);

        applyOptimisticSubmit(before, OPTIMISTIC_PUBLISH);

        expect(before).toEqual(snapshot);
    });
});

describe('targetStatusMeta', () => {
    it('maps publishing to a spinning active tone', () => {
        const meta = targetStatusMeta('publishing');
        expect(meta.spinning).toBe(true);
        expect(meta.tone).toBe('active');
    });

    it('maps published to a non-spinning success tone', () => {
        expect(targetStatusMeta('published')).toMatchObject({
            tone: 'success',
            spinning: false,
        });
    });
});

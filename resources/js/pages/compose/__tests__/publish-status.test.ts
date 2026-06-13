import { describe, expect, it } from 'vitest';

import {
    anyTargetActive,
    failedTargets,
    isPostTerminal,
    targetStatusMeta,
} from '../publish-status';
import type { PostView, TargetStatus, TargetView } from '../types';

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
        remote_id: null,
    };
}

function post(targets: TargetView[]): PostView {
    return {
        id: 'p1',
        base_text: 'hi',
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

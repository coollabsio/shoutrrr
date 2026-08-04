import { describe, expect, it } from 'vitest';

import { postCapabilities } from '@/lib/posts/capabilities';
import type { PostView } from '@/types/compose';

function post(partial: Partial<PostView>): PostView {
    return {
        id: 'p',
        base_text: '',
        status: 'draft',
        published_at: null,
        updated_at: '',
        scheduled_at: null,
        destination: { kind: 'all', id: null },
        targets: [],
        media: [],
        ...partial,
    } as PostView;
}

describe('postCapabilities', () => {
    it('draft: edit/schedule/delete, no duplicate', () => {
        const c = postCapabilities(post({ status: 'draft' }));
        expect(c).toMatchObject({
            canEdit: true,
            canSchedule: true,
            canDelete: true,
            canReschedule: false,
            canDuplicate: false,
        });
    });
    it('scheduled: edit/reschedule/unschedule/delete, no duplicate', () => {
        const c = postCapabilities(post({ status: 'scheduled' }));
        expect(c).toMatchObject({
            canReschedule: true,
            canUnschedule: true,
            canDelete: true,
            canSchedule: false,
            canDuplicate: false,
        });
    });
    it('failed with a failed target: delete + retry + duplicate', () => {
        const c = postCapabilities(
            post({
                status: 'failed',
                targets: [{ status: 'failed' } as PostView['targets'][number]],
            }),
        );
        expect(c).toMatchObject({
            canDelete: true,
            canRetry: true,
            canEdit: false,
            canDuplicate: true,
        });
    });
    it('published: delete + duplicate', () => {
        const c = postCapabilities(post({ status: 'published' }));
        expect(c).toMatchObject({
            canDelete: true,
            canDuplicate: true,
            canRetry: false,
        });
    });
    it('missed: reschedule + delete + duplicate', () => {
        const c = postCapabilities(post({ status: 'missed' }));
        expect(c).toMatchObject({
            canReschedule: true,
            canDelete: true,
            canDuplicate: true,
        });
    });
    it('publishing/deleted: nothing', () => {
        expect(postCapabilities(post({ status: 'publishing' })).canDelete).toBe(
            false,
        );
        expect(postCapabilities(post({ status: 'deleted' })).canDelete).toBe(
            false,
        );
    });
    it('tolerates a partial payload with no targets (canRetry false, no throw)', () => {
        const partial = { status: 'failed' } as unknown as PostView;
        expect(() => postCapabilities(partial)).not.toThrow();
        expect(postCapabilities(partial).canRetry).toBe(false);
    });
});

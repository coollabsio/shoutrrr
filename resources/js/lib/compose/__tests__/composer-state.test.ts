import { describe, expect, it } from 'vitest';

import {
    BASE_TAB,
    type Account,
    type MediaView,
    type PostView,
    type TargetView,
} from '@/types/compose';

import {
    buildPutBody,
    type ComposerState,
    composerHasContent,
    composerReducer,
    firstLineTitle,
    initialComposerState,
    isDraftBlank,
    parseDestinationParam,
    pickActiveAccount,
    segmentRefsFromBreaks,
    shouldShowConnectAccountPrompt,
} from '../composer-state';

function account(id: string): Account {
    return {
        id,
        platform: 'x',
        handle: `@${id}`,
        display_name: null,
        avatar_url: null,
        max_text_length: 280,
        x_premium: false,
    };
}

function mediaFixture(id: string): MediaView {
    return {
        id,
        url: `http://x/${id}.png`,
        mime: 'image/png',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        edit_url: 'http://x/raw',
        source_edit_url: null,
    };
}

function targetFixture(
    accountId: string,
    overrides: Partial<TargetView> = {},
): TargetView {
    return {
        id: `t-${accountId}`,
        connected_account_id: accountId,
        platform: 'x',
        handle: `@${accountId}`,
        display_name: null,
        avatar_url: null,
        sections: ['hello'],
        content_override: null,
        auto_split: true,
        format: 'feed',
        issues: [],
        status: 'pending',
        error_kind: null,
        error_message: null,
        attempts: 0,
        remote_id: null,
        ...overrides,
    };
}

function hydrated(): ReturnType<typeof composerReducer> {
    const post: PostView = {
        id: 'post-1',
        base_text: 'hello',
        segments: ['hello'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-06-12T10:00:00+00:00',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'all', id: null },
        targets: [
            {
                id: 't1',
                connected_account_id: 'a1',
                platform: 'x',
                handle: '@a',
                display_name: null,
                avatar_url: null,
                sections: ['hello'],
                content_override: null,
                auto_split: true,
                format: 'feed',
                issues: [],
                status: 'pending',
                error_kind: null,
                error_message: null,
                attempts: 0,
                remote_id: null,
            },
            {
                id: 't2',
                connected_account_id: 'a2',
                platform: 'bluesky',
                handle: '@b',
                display_name: null,
                avatar_url: null,
                sections: ['hello'],
                content_override: null,
                auto_split: true,
                format: 'feed',
                issues: [],
                status: 'pending',
                error_kind: null,
                error_message: null,
                attempts: 0,
                remote_id: null,
            },
        ],
        media: [],
    };

    return composerReducer(initialComposerState(), { type: 'hydrate', post });
}

describe('pickActiveAccount', () => {
    it('returns the account matching the active tab', () => {
        const accounts = [account('a1'), account('a2')];

        expect(pickActiveAccount(accounts, 'a2')?.id).toBe('a2');
    });

    it('falls back to the first account when the active tab is BASE_TAB (target-less draft with accounts connected)', () => {
        const accounts = [account('a1'), account('a2')];

        // A draft with no targets leaves activeTab at BASE_TAB; with accounts
        // connected the composer must still surface one (not the connect nudge).
        expect(pickActiveAccount(accounts, BASE_TAB)?.id).toBe('a1');
    });

    it('falls back to the first account when the active tab matches nothing', () => {
        const accounts = [account('a1')];

        expect(pickActiveAccount(accounts, 'stale-id')?.id).toBe('a1');
    });

    it('returns null when there are no accounts (genuine connect-an-account state)', () => {
        expect(pickActiveAccount([], BASE_TAB)).toBeNull();
    });
});

describe('shouldShowConnectAccountPrompt', () => {
    it('shows the nudge only when the workspace has no connected accounts', () => {
        expect(shouldShowConnectAccountPrompt([], null)).toBe(true);
        expect(shouldShowConnectAccountPrompt([account('a1')], null)).toBe(
            false,
        );
        expect(
            shouldShowConnectAccountPrompt([account('a1')], account('a1')),
        ).toBe(false);
    });
});

describe('composerReducer', () => {
    it('starts with no post and an idle save state', () => {
        const state = initialComposerState();
        expect(state.postId).toBeNull();
        expect(state.saveState).toBe('idle');
        expect(state.activeTab).toBe('__base__');
        expect(state.scheduleTray).toEqual({ mode: 'now', pickedAt: null });
    });

    it('pre-arms the schedule tray when given an initial schedule time', () => {
        const state = initialComposerState('2026-06-20T09:00:00Z');
        expect(state.scheduleTray).toEqual({
            mode: 'pick',
            pickedAt: '2026-06-20T09:00:00Z',
        });
    });

    it('hydrates segments, destination, baseline, and per-account maps', () => {
        const state = hydrated();
        expect(state.postId).toBe('post-1');
        expect(state.segments).toEqual(['hello']);
        expect(state.baselineUpdatedAt).toBe('2026-06-12T10:00:00+00:00');
        expect(state.autoSplitByAccount).toEqual({ a1: true, a2: true });
        expect(state.saveState).toBe('saved');
    });

    it('marks dirty when segments change', () => {
        const state = composerReducer(hydrated(), {
            type: 'updateSegments',
            segments: ['new'],
        });
        expect(state.segments).toEqual(['new']);
        expect(state.saveState).toBe('dirty');
    });

    it('does not mark dirty when segments are unchanged', () => {
        const saved = hydrated();
        const next = composerReducer(saved, {
            type: 'updateSegments',
            segments: ['hello'],
        });

        expect(next).toBe(saved);
        expect(next.saveState).toBe('saved');
    });

    it('stores a per-account override and marks dirty', () => {
        const state = composerReducer(hydrated(), {
            type: 'setOverrideSegments',
            accountId: 'a1',
            segments: ['just for x'],
        });
        expect(state.overrideByAccount.a1).toEqual(['just for x']);
        expect(state.saveState).toBe('dirty');
    });

    it('discards a per-account override', () => {
        let state = composerReducer(hydrated(), {
            type: 'setOverrideSegments',
            accountId: 'a1',
            segments: ['x'],
        });
        state = composerReducer(state, {
            type: 'discardOverride',
            accountId: 'a1',
        });
        expect(state.overrideByAccount.a1).toBeUndefined();
    });

    it('toggles auto split per account', () => {
        const state = composerReducer(hydrated(), {
            type: 'toggleAutoSplit',
            accountId: 'a1',
        });
        expect(state.autoSplitByAccount.a1).toBe(false);
    });

    it('disables auto split for all selected accounts after a manual split', () => {
        const state = composerReducer(hydrated(), {
            type: 'disableAutoSplit',
            accountIds: ['a1', 'a2'],
        });

        expect(state.autoSplitByAccount).toEqual({ a1: false, a2: false });
        expect(state.saveState).toBe('dirty');
    });

    it('stores the tri-state auto-repost override and marks dirty', () => {
        let state = composerReducer(hydrated(), {
            type: 'setAutoRepost',
            value: true,
        });
        expect(state.autoRepost).toBe(true);
        expect(state.saveState).toBe('dirty');

        state = composerReducer(state, { type: 'setAutoRepost', value: false });
        expect(state.autoRepost).toBe(false);

        state = composerReducer(state, { type: 'setAutoRepost', value: null });
        expect(state.autoRepost).toBeNull();
    });

    it('transitions through a successful save', () => {
        let state = composerReducer(hydrated(), {
            type: 'updateSegments',
            segments: ['new'],
        });
        state = composerReducer(state, { type: 'saveStarted' });
        expect(state.saveState).toBe('saving');

        const view: PostView = {
            id: 'post-1',
            base_text: 'new',
            segments: ['new'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T11:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        state = composerReducer(state, { type: 'saveSucceeded', post: view });
        expect(state.saveState).toBe('saved');
        expect(state.baselineUpdatedAt).toBe('2026-06-12T11:00:00+00:00');
    });

    it('keeps dirty on save success when edits arrived mid-flight', () => {
        let state = composerReducer(hydrated(), { type: 'saveStarted' });
        // user types while the save is in flight
        state = composerReducer(state, {
            type: 'updateSegments',
            segments: ['typed during save'],
        });
        expect(state.saveState).toBe('dirty');

        const view: PostView = {
            id: 'post-1',
            base_text: 'hello',
            segments: ['hello'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T11:30:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        state = composerReducer(state, { type: 'saveSucceeded', post: view });
        // stays dirty so the debounce reschedules and the edit is not lost
        expect(state.saveState).toBe('dirty');
        expect(state.baselineUpdatedAt).toBe('2026-06-12T11:30:00+00:00');
        expect(state.conflict).toBeNull();
    });

    it('tracks media via addMedia and removeMedia and marks dirty', () => {
        let state = composerReducer(hydrated(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        expect(state.media.map((m) => m.id)).toEqual(['m1']);
        expect(state.saveState).toBe('dirty');

        state = composerReducer(state, {
            type: 'addMedia',
            media: {
                id: 'm2',
                url: 'http://x/m2.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 1,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        expect(state.media.map((m) => m.id)).toEqual(['m1', 'm2']);

        state = composerReducer(state, {
            type: 'removeMedia',
            mediaId: 'm1',
        });
        expect(state.media.map((m) => m.id)).toEqual(['m2']);
        expect(state.saveState).toBe('dirty');
    });

    it('reorders media to match the given id sequence and marks dirty', () => {
        let state = composerReducer(hydrated(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        state = composerReducer(state, {
            type: 'addMedia',
            media: {
                id: 'm2',
                url: 'http://x/m2.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 1,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        state = composerReducer(state, {
            type: 'reorderMedia',
            ids: ['m2', 'm1'],
        });
        expect(state.media.map((m) => m.id)).toEqual(['m2', 'm1']);
        expect(state.saveState).toBe('dirty');
    });

    it('appends media missing from a partial reorder sequence', () => {
        let state = composerReducer(hydrated(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        state = composerReducer(state, {
            type: 'addMedia',
            media: {
                id: 'm2',
                url: 'http://x/m2.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 1,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        // unknown id ignored; m1 missing from the sequence is appended
        state = composerReducer(state, {
            type: 'reorderMedia',
            ids: ['m2', 'ghost'],
        });
        expect(state.media.map((m) => m.id)).toEqual(['m2', 'm1']);
    });

    it('enters conflict on a stale save and resolves use-server', () => {
        let state = composerReducer(hydrated(), {
            type: 'updateSegments',
            segments: ['mine'],
        });
        const server: PostView = {
            id: 'post-1',
            base_text: 'theirs',
            segments: ['theirs'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T12:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        state = composerReducer(state, {
            type: 'saveFailedStale',
            post: server,
        });
        expect(state.saveState).toBe('conflict');
        expect(state.conflict?.segments).toEqual(['theirs']);

        state = composerReducer(state, { type: 'resolveConflictUseServer' });
        expect(state.segments).toEqual(['theirs']);
        expect(state.saveState).toBe('saved');
        expect(state.conflict).toBeNull();
    });

    it('syncServerPost adopts a newer server version of the same post (schedule/publish bumped updated_at out-of-band)', () => {
        // After a schedule/queue/publish mutation bumps updated_at via its own
        // request, the page reloads with the newer post. The composer must
        // re-baseline or the next autosave would 409 against the user's change.
        const saved = hydrated();
        expect(saved.baselineUpdatedAt).toBe('2026-06-12T10:00:00+00:00');

        const server: PostView = {
            id: 'post-1',
            base_text: 'hello',
            segments: ['hello'],
            status: 'scheduled',
            published_at: null,
            updated_at: '2026-06-12T13:00:00+00:00',
            scheduled_at: '2026-06-20T09:00:00+00:00',
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'syncServerPost',
            post: server,
        });
        expect(next.baselineUpdatedAt).toBe('2026-06-12T13:00:00+00:00');
        expect(next.saveState).toBe('saved');
    });

    it('syncServerPost is a no-op when the server version matches the baseline', () => {
        const saved = hydrated();
        const same: PostView = {
            id: 'post-1',
            base_text: 'hello',
            segments: ['hello'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T10:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'syncServerPost',
            post: same,
        });
        expect(next).toBe(saved);
    });

    it('syncServerPost preserves local edits when the composer is dirty', () => {
        const dirty = composerReducer(hydrated(), {
            type: 'updateSegments',
            segments: ['my unsaved edit'],
        });
        const server: PostView = {
            id: 'post-1',
            base_text: 'hello',
            segments: ['hello'],
            status: 'scheduled',
            published_at: null,
            updated_at: '2026-06-12T13:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(dirty, {
            type: 'syncServerPost',
            post: server,
        });
        expect(next).toBe(dirty);
        expect(next.segments).toEqual(['my unsaved edit']);
        expect(next.saveState).toBe('dirty');
    });

    it('syncServerPost fully re-hydrates when navigating to a different post', () => {
        const saved = hydrated();
        const other: PostView = {
            id: 'post-2',
            base_text: 'a different draft',
            segments: ['a different draft'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T14:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'syncServerPost',
            post: other,
        });
        expect(next.postId).toBe('post-2');
        expect(next.segments).toEqual(['a different draft']);
        expect(next.baselineUpdatedAt).toBe('2026-06-12T14:00:00+00:00');
    });

    it('auto-resolves a stale 409 silently when server content is identical (false conflict)', () => {
        // The user typed "test", it saved, then updated_at moved out-of-band
        // (e.g. a schedule/publish). A retry 409s, but the server text matches —
        // no dialog should appear; the baseline just advances.
        const saved = hydrated(); // segments ['hello'], no overrides/media
        const server: PostView = {
            id: 'post-1',
            base_text: 'hello',
            segments: ['hello'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T15:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'saveFailedStale',
            post: server,
        });
        expect(next.saveState).toBe('saved');
        expect(next.conflict).toBeNull();
        expect(next.baselineUpdatedAt).toBe('2026-06-12T15:00:00+00:00');
    });

    it('still opens the conflict dialog when server content genuinely differs', () => {
        const saved = hydrated();
        const server: PostView = {
            id: 'post-1',
            base_text: 'a real concurrent edit',
            segments: ['a real concurrent edit'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T15:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'saveFailedStale',
            post: server,
        });
        expect(next.saveState).toBe('conflict');
        expect(next.conflict?.segments).toEqual(['a real concurrent edit']);
    });

    it('drops dirty back to idle on saveSkippedEmpty (empty composer, no draft)', () => {
        // A destination change marks an empty new composer dirty; the autosave
        // guard then skips the create and dispatches saveSkippedEmpty.
        const dirty = composerReducer(initialComposerState(), {
            type: 'setDestination',
            destination: { kind: 'account', id: 'a1' },
        });
        expect(dirty.saveState).toBe('dirty');

        const skipped = composerReducer(dirty, { type: 'saveSkippedEmpty' });
        expect(skipped.saveState).toBe('idle');
        // destination still updated — only the dirty flag was cleared
        expect(skipped.destination).toEqual({ kind: 'account', id: 'a1' });
    });

    it('leaves a non-dirty state untouched on saveSkippedEmpty', () => {
        const saved = hydrated();
        expect(saved.saveState).toBe('saved');
        expect(
            composerReducer(saved, { type: 'saveSkippedEmpty' }).saveState,
        ).toBe('saved');
    });

    it('replaceMedia swaps a media entry in place by id', () => {
        const base = composerReducer(hydrated(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        const existing = base.media[0];
        const next = composerReducer(base, {
            type: 'replaceMedia',
            media: { ...existing, url: 'new-url' },
        });
        expect(next.media.find((m) => m.id === existing.id)?.url).toBe(
            'new-url',
        );
        expect(next.media.length).toBe(base.media.length);
    });

    it('replaces the schedule tray without touching saveState', () => {
        const state = hydrated();
        expect(state.scheduleTray).toEqual({ mode: 'now', pickedAt: null });
        const next = composerReducer(state, {
            type: 'setScheduleTray',
            tray: { mode: 'pick', pickedAt: '2026-06-20T15:00:00+00:00' },
        });
        expect(next.scheduleTray).toEqual({
            mode: 'pick',
            pickedAt: '2026-06-20T15:00:00+00:00',
        });
        // scheduling is separate from the autosave dirty flow
        expect(next.saveState).toBe(state.saveState);
    });

    it('resolves keep-mine by adopting the server baseline but keeping my segments', () => {
        let state = composerReducer(hydrated(), {
            type: 'updateSegments',
            segments: ['mine'],
        });
        const server: PostView = {
            id: 'post-1',
            base_text: 'theirs',
            segments: ['theirs'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T12:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        state = composerReducer(state, {
            type: 'saveFailedStale',
            post: server,
        });
        state = composerReducer(state, { type: 'resolveConflictKeepMine' });
        expect(state.segments).toEqual(['mine']);
        expect(state.baselineUpdatedAt).toBe('2026-06-12T12:00:00+00:00');
        expect(state.saveState).toBe('dirty');
    });
});

describe('buildPutBody', () => {
    it('sends content_override: null for accounts without an override', () => {
        const state = hydrated();
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.targets[0]).toEqual({
            connected_account_id: 'a1',
            auto_split: true,
            format: 'feed',
            content_override: null,
        });
        expect(body.targets[0].content_override).toBeNull();
    });

    it('includes content_override only for overridden accounts and clears the rest', () => {
        const state = composerReducer(hydrated(), {
            type: 'setOverrideSegments',
            accountId: 'a1',
            segments: ['x only'],
        });
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.targets[0].content_override).toEqual({
            segments: ['x only'],
            media_ids: [],
        });
        expect(body.targets[1].content_override).toBeNull();
    });

    it('carries segments, destination, media, and the baseline', () => {
        const state = hydrated();
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.segments).toEqual(['hello']);
        expect(body.destination).toEqual({ kind: 'all' });
        expect(body.expected_updated_at).toBe('2026-06-12T10:00:00+00:00');
    });

    it('carries a custom multi-account destination', () => {
        const state = composerReducer(hydrated(), {
            type: 'setDestination',
            destination: { kind: 'accounts', ids: ['a1', 'a2'] },
        });
        const body = buildPutBody(state, ['a1', 'a2']);

        expect(body.destination).toEqual({
            kind: 'accounts',
            ids: ['a1', 'a2'],
        });
    });

    it('emits media_ids from state.media', () => {
        let state = composerReducer(hydrated(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        state = composerReducer(state, {
            type: 'addMedia',
            media: {
                id: 'm2',
                url: 'http://x/m2.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 1,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.media_ids).toEqual(['m1', 'm2']);
    });
});

describe('composerHasContent', () => {
    it('is false for a fresh, empty composer (only destination/schedule set)', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'setDestination',
            destination: { kind: 'account', id: 'a1' },
        });
        expect(composerHasContent(state)).toBe(false);
    });

    it('is false when segments are only whitespace', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'updateSegments',
            segments: ['   \n  '],
        });
        expect(composerHasContent(state)).toBe(false);
    });

    it('is true once segments have non-whitespace', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'updateSegments',
            segments: ['hi'],
        });
        expect(composerHasContent(state)).toBe(true);
    });

    it('is true when media is attached, even with empty segments', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        expect(composerHasContent(state)).toBe(true);
    });

    it('is true when a per-account override has text but base segments are empty', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'setOverrideSegments',
            accountId: 'a1',
            segments: ['just for x'],
        });
        expect(composerHasContent(state)).toBe(true);
    });

    it('ignores a whitespace-only override', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'setOverrideSegments',
            accountId: 'a1',
            segments: ['   '],
        });
        expect(composerHasContent(state)).toBe(false);
    });
});

describe('isDraftBlank', () => {
    it('is true for a fresh composer with a single, empty thread', () => {
        expect(isDraftBlank(initialComposerState())).toBe(true);
    });

    it('is false once text is typed', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'updateSegments',
            segments: ['hi'],
        });
        expect(isDraftBlank(state)).toBe(false);
    });

    it('is false once a second thread exists, even with no text yet', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'setSegmentBreaks',
            breakIds: ['b1'],
        });
        expect(isDraftBlank(state)).toBe(false);
    });

    it('is false once media is attached', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'addMedia',
            media: {
                id: 'm1',
                url: 'http://x/m1.png',
                mime: 'image/png',
                kind: 'image',
                alt_text: null,
                duration_seconds: null,
                position: 0,
                edit_settings: null,
                source_url: null,
                edit_url: 'http://x/raw',
                source_edit_url: null,
            },
        });
        expect(isDraftBlank(state)).toBe(false);
    });
});

describe('parseDestinationParam', () => {
    it('parses all / account / set', () => {
        expect(parseDestinationParam('all')).toEqual({ kind: 'all' });
        expect(parseDestinationParam('account:abc')).toEqual({
            kind: 'account',
            id: 'abc',
        });
        expect(parseDestinationParam('set:xyz')).toEqual({
            kind: 'set',
            id: 'xyz',
        });
        expect(parseDestinationParam('accounts:a,b')).toEqual({
            kind: 'accounts',
            ids: ['a', 'b'],
        });
    });

    it('returns null for junk or missing input', () => {
        expect(parseDestinationParam(null)).toBeNull();
        expect(parseDestinationParam('nope')).toBeNull();
        expect(parseDestinationParam('account:')).toBeNull();
        expect(parseDestinationParam('accounts:')).toBeNull();
    });
});

describe('initialComposerState with a destination', () => {
    it('seeds the destination', () => {
        expect(
            initialComposerState(null, { kind: 'account', id: 'abc' })
                .destination,
        ).toEqual({ kind: 'account', id: 'abc' });
    });

    it('defaults to all', () => {
        expect(initialComposerState().destination).toEqual({ kind: 'all' });
    });
});

describe('composer format state', () => {
    it('setFormat records the per-account format and marks dirty', () => {
        const state = initialComposerState();
        const next = composerReducer(state, {
            type: 'setFormat',
            accountId: 'acc-1',
            format: 'story',
        });

        expect(next.formatByAccount['acc-1']).toBe('story');
        expect(next.saveState).toBe('dirty');
    });

    it('buildPutBody emits format per target, defaulting to feed', () => {
        let state = initialComposerState();
        state = composerReducer(state, {
            type: 'setFormat',
            accountId: 'acc-1',
            format: 'reels',
        });

        const body = buildPutBody(state, ['acc-1', 'acc-2']);
        expect(body.targets[0]).toMatchObject({
            connected_account_id: 'acc-1',
            format: 'reels',
        });
        expect(body.targets[1].format).toBe('feed');
    });
});

describe('per-segment placements', () => {
    it('adds media to the active segment placement', () => {
        let s = initialComposerState();
        s = { ...s, segmentBreaks: ['b1'] };
        s = composerReducer(s, {
            type: 'addMedia',
            media: mediaFixture('m1'),
            segmentRef: 'b1',
        });
        expect(s.media.map((m) => m.id)).toEqual(['m1']);
        expect(s.placements.b1).toEqual(['m1']);
    });

    it('addMedia without a segmentRef defaults to __head__', () => {
        let s = initialComposerState();
        s = composerReducer(s, { type: 'addMedia', media: mediaFixture('m1') });
        expect(s.placements.__head__).toEqual(['m1']);
    });

    it('hydrate folds media that has no placement onto the first segment', () => {
        const post: PostView = {
            id: 'post-1',
            base_text: 'test',
            segments: ['test'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T10:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            targets: [],
            // A media item with no placements array at all (legacy draft).
            media: [mediaFixture('m1')],
        };
        const s = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });
        expect(s.media.map((m) => m.id)).toEqual(['m1']);
        expect(s.placements.__head__).toEqual(['m1']);
    });

    it('moving media on an account tab diverges only that account', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            placements: { __head__: ['m1'] },
        };
        s = composerReducer(s, {
            type: 'moveMediaToSegment',
            mediaId: 'm1',
            segmentRef: 'b1',
            accountId: 'acc-x',
        });
        expect(s.placements.__head__).toEqual(['m1']); // canonical untouched
        expect(s.placementsByAccount['acc-x'].b1).toEqual(['m1']);
    });

    it('moving media with no accountId updates the canonical placements', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            placements: { __head__: ['m1'] },
        };
        s = composerReducer(s, {
            type: 'moveMediaToSegment',
            mediaId: 'm1',
            segmentRef: 'b1',
        });
        expect(s.placements.__head__ ?? []).toEqual([]);
        expect(s.placements.b1).toEqual(['m1']);
    });

    it('removeMediaFromSegments without an accountId drops the media entirely and un-places it everywhere', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            placements: { __head__: ['m1'] },
            placementsByAccount: { 'acc-x': { b1: ['m1'] } },
        };
        s = composerReducer(s, {
            type: 'removeMediaFromSegments',
            mediaId: 'm1',
        });
        expect(s.media).toEqual([]);
        expect(s.placements.__head__ ?? []).toEqual([]);
        expect(s.placementsByAccount['acc-x'].b1 ?? []).toEqual([]);
    });

    it('removeMediaFromSegments with an accountId only un-places for that account, keeping the file', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            placements: { __head__: ['m1'] },
            placementsByAccount: { 'acc-x': { b1: ['m1'] } },
        };
        s = composerReducer(s, {
            type: 'removeMediaFromSegments',
            mediaId: 'm1',
            accountId: 'acc-x',
        });
        expect(s.media.map((m) => m.id)).toEqual(['m1']);
        expect(s.placements.__head__).toEqual(['m1']);
        expect(s.placementsByAccount['acc-x'].b1 ?? []).toEqual([]);
    });

    it('removeMediaFromSegments with an accountId only touches that account, leaving canonical and other diverged accounts untouched', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            placements: { __head__: ['m1'] },
            placementsByAccount: {
                'acc-x': { b1: ['m1'] },
                'acc-y': { b1: ['m1'] },
            },
        };
        s = composerReducer(s, {
            type: 'removeMediaFromSegments',
            mediaId: 'm1',
            accountId: 'acc-x',
        });
        expect(s.placements.__head__).toEqual(['m1']);
        expect(s.placementsByAccount['acc-x'].b1 ?? []).toEqual([]);
        expect(s.placementsByAccount['acc-y'].b1).toEqual(['m1']);
    });

    it('reorderSegmentMedia sets the segment order in the given scope', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            placements: { __head__: ['m1', 'm2'] },
        };
        s = composerReducer(s, {
            type: 'reorderSegmentMedia',
            segmentRef: '__head__',
            ids: ['m2', 'm1'],
        });
        expect(s.placements.__head__).toEqual(['m2', 'm1']);
    });

    it('setSegmentBreaks replaces state.segmentBreaks', () => {
        const s = composerReducer(initialComposerState(), {
            type: 'setSegmentBreaks',
            breakIds: ['b1', 'b2'],
        });
        expect(s.segmentBreaks).toEqual(['b1', 'b2']);
    });

    it('deleting a break folds its media into the segment it merged into, not orphaned', () => {
        // Two segments: __head__ has m1, the second (b1) has m2 — mirrors two
        // threads each with their own attached image.
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1'), mediaFixture('m2')],
            segmentBreaks: ['b1'],
            placements: { __head__: ['m1'], b1: ['m2'] },
        };
        // Backspacing at the start of the second segment merges it into the
        // first — the break disappears from the doc's breakIds.
        s = composerReducer(s, { type: 'setSegmentBreaks', breakIds: [] });

        expect(s.segmentBreaks).toEqual([]);
        // Both media now live under the single surviving segment — neither is
        // dropped, and the media pool (the "2" the badge counts) is untouched.
        expect(s.placements.__head__ ?? []).toEqual(
            expect.arrayContaining(['m1', 'm2']),
        );
        expect(s.placements.__head__).toHaveLength(2);
        expect(s.placements.b1).toBeUndefined();
        expect(s.media.map((m) => m.id)).toEqual(['m1', 'm2']);
    });

    it('deleting a middle break re-homes its media on the nearest surviving earlier segment', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            segmentBreaks: ['b1', 'b2'],
            placements: { b1: ['m1'] },
        };
        // b1 is removed (its segment merged backward); b2 survives.
        s = composerReducer(s, { type: 'setSegmentBreaks', breakIds: ['b2'] });

        expect(s.placements.__head__).toEqual(['m1']);
        expect(s.placements.b1).toBeUndefined();
    });

    it('deleting a break also re-homes per-account diverged placements', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            segmentBreaks: ['b1'],
            placements: {},
            placementsByAccount: { 'acc-x': { b1: ['m1'] } },
        };
        s = composerReducer(s, { type: 'setSegmentBreaks', breakIds: [] });

        expect(s.placementsByAccount['acc-x'].__head__).toEqual(['m1']);
        expect(s.placementsByAccount['acc-x'].b1).toBeUndefined();
    });

    it('adding a break (no removals) leaves existing placements untouched', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            segmentBreaks: [],
            placements: { __head__: ['m1'] },
        };
        s = composerReducer(s, {
            type: 'setSegmentBreaks',
            breakIds: ['b1'],
        });

        expect(s.placements.__head__).toEqual(['m1']);
        expect(s.placements.b1 ?? []).toEqual([]);
    });

    it('removeMedia also drops the id from canonical and per-account placements', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            media: [mediaFixture('m1')],
            placements: { __head__: ['m1'] },
            placementsByAccount: { 'acc-x': { b1: ['m1'] } },
        };
        s = composerReducer(s, { type: 'removeMedia', mediaId: 'm1' });
        expect(s.media).toEqual([]);
        expect(s.placements.__head__ ?? []).toEqual([]);
        expect(s.placementsByAccount['acc-x'].b1 ?? []).toEqual([]);
    });

    it('buildPutBody flattens canonical placements', () => {
        const s = {
            ...initialComposerState(),
            segmentBreaks: ['b1'],
            placements: { __head__: ['m1'], b1: ['m2'] },
            media: [mediaFixture('m1'), mediaFixture('m2')],
        };
        const body = buildPutBody(s, ['acc-x']);
        expect(body.segment_breaks).toEqual(['b1']);
        expect(body.placements).toContainEqual({
            media_id: 'm1',
            segment_ref: '__head__',
            position: 0,
        });
        expect(body.placements).toContainEqual({
            media_id: 'm2',
            segment_ref: 'b1',
            position: 0,
        });
    });

    it('buildPutBody omits per-target placements for accounts that have not diverged', () => {
        const s = {
            ...initialComposerState(),
            placements: { __head__: ['m1'] },
        };
        const body = buildPutBody(s, ['acc-x']);
        expect(body.targets[0].placements).toBeUndefined();
        expect(body.targets[0].segment_breaks).toBeUndefined();
    });

    it('buildPutBody includes per-target placements only for a diverged account', () => {
        let s: ComposerState = {
            ...initialComposerState(),
            segmentBreaks: ['b1'],
            placements: { __head__: ['m1'] },
        };
        s = composerReducer(s, {
            type: 'moveMediaToSegment',
            mediaId: 'm1',
            segmentRef: 'b1',
            accountId: 'acc-x',
        });
        const body = buildPutBody(s, ['acc-x', 'acc-y']);
        expect(body.targets[0].connected_account_id).toBe('acc-x');
        expect(body.targets[0].segment_breaks).toEqual(['b1']);
        expect(body.targets[0].placements).toContainEqual({
            media_id: 'm1',
            segment_ref: 'b1',
            position: 0,
        });
        expect(body.targets[1].placements).toBeUndefined();
    });

    it('hydrate does NOT create a placementsByAccount entry for a target whose placements match canonical, so a later canonical edit still propagates to it', () => {
        const post: PostView = {
            id: 'post-1',
            base_text: 'hello',
            segments: ['hello'],
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T10:00:00+00:00',
            scheduled_at: null,
            auto_repost: null,
            destination: { kind: 'all', id: null },
            segment_breaks: [],
            placements: [
                { media_id: 'm1', segment_ref: '__head__', position: 0 },
            ],
            targets: [
                targetFixture('a1', {
                    placements: [
                        {
                            media_id: 'm1',
                            segment_ref: '__head__',
                            position: 0,
                        },
                    ],
                }),
                targetFixture('a2', {
                    placements: [
                        { media_id: 'm1', segment_ref: 'b1', position: 0 },
                    ],
                }),
            ],
            media: [mediaFixture('m1')],
        };

        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post,
        });

        // a1 matches canonical exactly -> no entry; a2 genuinely diverges -> entry.
        expect(state.placementsByAccount.a1).toBeUndefined();
        expect(state.placementsByAccount.a2).toEqual({ b1: ['m1'] });

        // A canonical-scope edit (no accountId) must still reach a1, since it
        // has no stale per-account snapshot pinning it to the pre-edit state.
        state = composerReducer(state, {
            type: 'addMedia',
            media: mediaFixture('m2'),
        });
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.targets[0].connected_account_id).toBe('a1');
        expect(body.targets[0].placements).toBeUndefined(); // still inherits canonical
        expect(body.placements).toContainEqual({
            media_id: 'm2',
            segment_ref: '__head__',
            position: 1,
        });
    });
});

describe('segmentRefsFromBreaks', () => {
    it('prefixes __head__ before the ordered break ids', () => {
        expect(segmentRefsFromBreaks(['b1', 'b2'])).toEqual([
            '__head__',
            'b1',
            'b2',
        ]);
    });

    it('returns just __head__ for no breaks', () => {
        expect(segmentRefsFromBreaks([])).toEqual(['__head__']);
    });
});

describe('firstLineTitle', () => {
    it('returns an empty string for empty segments', () => {
        expect(firstLineTitle([''])).toBe('');
    });

    it('returns an empty string when there is no non-empty line', () => {
        expect(firstLineTitle(['   \n\n  \n'])).toBe('');
    });

    it('picks the first non-empty line across segments, trimmed', () => {
        expect(firstLineTitle(['\n  \n  hello world  \nsecond'])).toBe(
            'hello world',
        );
    });

    it('truncates lines longer than 80 chars with an ellipsis', () => {
        const long = 'a'.repeat(120);
        const title = firstLineTitle([long]);
        expect(title).toBe(`${'a'.repeat(80)}…`);
        expect(title.length).toBe(81);
    });

    it('keeps lines of exactly 80 chars untouched', () => {
        const exact = 'b'.repeat(80);
        expect(firstLineTitle([exact])).toBe(exact);
    });
});

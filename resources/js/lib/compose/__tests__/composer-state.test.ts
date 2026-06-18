import { describe, expect, it } from 'vitest';

import { BASE_TAB, type Account, type PostView } from '@/types/compose';

import {
    buildPutBody,
    composerHasContent,
    composerReducer,
    firstLineTitle,
    initialComposerState,
    pickActiveAccount,
} from '../composer-state';

function account(id: string): Account {
    return {
        id,
        platform: 'x',
        handle: `@${id}`,
        display_name: null,
        avatar_url: null,
    };
}

function hydrated(): ReturnType<typeof composerReducer> {
    const post: PostView = {
        id: 'post-1',
        base_text: 'hello',
        status: 'draft',
        published_at: null,
        updated_at: '2026-06-12T10:00:00+00:00',
        scheduled_at: null,
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
                issues: [],
                status: 'pending',
                error_kind: null,
                error_message: null,
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
                issues: [],
                status: 'pending',
                error_kind: null,
                error_message: null,
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

    it('hydrates base text, destination, baseline, and per-account maps', () => {
        const state = hydrated();
        expect(state.postId).toBe('post-1');
        expect(state.baseText).toBe('hello');
        expect(state.baselineUpdatedAt).toBe('2026-06-12T10:00:00+00:00');
        expect(state.autoSplitByAccount).toEqual({ a1: true, a2: true });
        expect(state.saveState).toBe('saved');
    });

    it('marks dirty when base text changes', () => {
        const state = composerReducer(hydrated(), {
            type: 'updateBaseText',
            text: 'new',
        });
        expect(state.baseText).toBe('new');
        expect(state.saveState).toBe('dirty');
    });

    it('stores a per-account override and marks dirty', () => {
        const state = composerReducer(hydrated(), {
            type: 'setOverrideText',
            accountId: 'a1',
            text: 'just for x',
        });
        expect(state.overrideByAccount.a1).toBe('just for x');
        expect(state.saveState).toBe('dirty');
    });

    it('discards a per-account override', () => {
        let state = composerReducer(hydrated(), {
            type: 'setOverrideText',
            accountId: 'a1',
            text: 'x',
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

    it('transitions through a successful save', () => {
        let state = composerReducer(hydrated(), {
            type: 'updateBaseText',
            text: 'new',
        });
        state = composerReducer(state, { type: 'saveStarted' });
        expect(state.saveState).toBe('saving');

        const view: PostView = {
            id: 'post-1',
            base_text: 'new',
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T11:00:00+00:00',
            scheduled_at: null,
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
            type: 'updateBaseText',
            text: 'typed during save',
        });
        expect(state.saveState).toBe('dirty');

        const view: PostView = {
            id: 'post-1',
            base_text: 'hello',
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T11:30:00+00:00',
            scheduled_at: null,
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
            type: 'updateBaseText',
            text: 'mine',
        });
        const server: PostView = {
            id: 'post-1',
            base_text: 'theirs',
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T12:00:00+00:00',
            scheduled_at: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        state = composerReducer(state, {
            type: 'saveFailedStale',
            post: server,
        });
        expect(state.saveState).toBe('conflict');
        expect(state.conflict?.base_text).toBe('theirs');

        state = composerReducer(state, { type: 'resolveConflictUseServer' });
        expect(state.baseText).toBe('theirs');
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
            status: 'scheduled',
            published_at: null,
            updated_at: '2026-06-12T13:00:00+00:00',
            scheduled_at: '2026-06-20T09:00:00+00:00',
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
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T10:00:00+00:00',
            scheduled_at: null,
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
            type: 'updateBaseText',
            text: 'my unsaved edit',
        });
        const server: PostView = {
            id: 'post-1',
            base_text: 'hello',
            status: 'scheduled',
            published_at: null,
            updated_at: '2026-06-12T13:00:00+00:00',
            scheduled_at: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(dirty, {
            type: 'syncServerPost',
            post: server,
        });
        expect(next).toBe(dirty);
        expect(next.baseText).toBe('my unsaved edit');
        expect(next.saveState).toBe('dirty');
    });

    it('syncServerPost fully re-hydrates when navigating to a different post', () => {
        const saved = hydrated();
        const other: PostView = {
            id: 'post-2',
            base_text: 'a different draft',
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T14:00:00+00:00',
            scheduled_at: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'syncServerPost',
            post: other,
        });
        expect(next.postId).toBe('post-2');
        expect(next.baseText).toBe('a different draft');
        expect(next.baselineUpdatedAt).toBe('2026-06-12T14:00:00+00:00');
    });

    it('auto-resolves a stale 409 silently when server content is identical (false conflict)', () => {
        // The user typed "test", it saved, then updated_at moved out-of-band
        // (e.g. a schedule/publish). A retry 409s, but the server text matches —
        // no dialog should appear; the baseline just advances.
        const saved = hydrated(); // base_text 'hello', no overrides/media
        const server: PostView = {
            id: 'post-1',
            base_text: 'hello',
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T15:00:00+00:00',
            scheduled_at: null,
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
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T15:00:00+00:00',
            scheduled_at: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        const next = composerReducer(saved, {
            type: 'saveFailedStale',
            post: server,
        });
        expect(next.saveState).toBe('conflict');
        expect(next.conflict?.base_text).toBe('a real concurrent edit');
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

    it('resolves keep-mine by adopting the server baseline but keeping my text', () => {
        let state = composerReducer(hydrated(), {
            type: 'updateBaseText',
            text: 'mine',
        });
        const server: PostView = {
            id: 'post-1',
            base_text: 'theirs',
            status: 'draft',
            published_at: null,
            updated_at: '2026-06-12T12:00:00+00:00',
            scheduled_at: null,
            destination: { kind: 'all', id: null },
            targets: [],
            media: [],
        };
        state = composerReducer(state, {
            type: 'saveFailedStale',
            post: server,
        });
        state = composerReducer(state, { type: 'resolveConflictKeepMine' });
        expect(state.baseText).toBe('mine');
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
            content_override: null,
        });
        expect(body.targets[0].content_override).toBeNull();
    });

    it('includes content_override only for overridden accounts and clears the rest', () => {
        const state = composerReducer(hydrated(), {
            type: 'setOverrideText',
            accountId: 'a1',
            text: 'x only',
        });
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.targets[0].content_override).toEqual({
            text: 'x only',
            media_ids: [],
        });
        expect(body.targets[1].content_override).toBeNull();
    });

    it('carries base_text, destination, media, and the baseline', () => {
        const state = hydrated();
        const body = buildPutBody(state, ['a1', 'a2']);
        expect(body.base_text).toBe('hello');
        expect(body.destination).toEqual({ kind: 'all' });
        expect(body.expected_updated_at).toBe('2026-06-12T10:00:00+00:00');
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

    it('is false when base text is only whitespace', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'updateBaseText',
            text: '   \n  ',
        });
        expect(composerHasContent(state)).toBe(false);
    });

    it('is true once base text has non-whitespace', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'updateBaseText',
            text: 'hi',
        });
        expect(composerHasContent(state)).toBe(true);
    });

    it('is true when media is attached, even with empty text', () => {
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
            },
        });
        expect(composerHasContent(state)).toBe(true);
    });

    it('is true when a per-account override has text but base text is empty', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'setOverrideText',
            accountId: 'a1',
            text: 'just for x',
        });
        expect(composerHasContent(state)).toBe(true);
    });

    it('ignores a whitespace-only override', () => {
        const state = composerReducer(initialComposerState(), {
            type: 'setOverrideText',
            accountId: 'a1',
            text: '   ',
        });
        expect(composerHasContent(state)).toBe(false);
    });
});

describe('firstLineTitle', () => {
    it('returns an empty string for empty text', () => {
        expect(firstLineTitle('')).toBe('');
    });

    it('returns an empty string when there is no non-empty line', () => {
        expect(firstLineTitle('   \n\n  \n')).toBe('');
    });

    it('picks the first non-empty line, trimmed', () => {
        expect(firstLineTitle('\n  \n  hello world  \nsecond')).toBe(
            'hello world',
        );
    });

    it('truncates lines longer than 80 chars with an ellipsis', () => {
        const long = 'a'.repeat(120);
        const title = firstLineTitle(long);
        expect(title).toBe(`${'a'.repeat(80)}…`);
        expect(title.length).toBe(81);
    });

    it('keeps lines of exactly 80 chars untouched', () => {
        const exact = 'b'.repeat(80);
        expect(firstLineTitle(exact)).toBe(exact);
    });
});

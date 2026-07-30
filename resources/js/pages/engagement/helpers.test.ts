/** @vitest-environment jsdom */

import { describe, expect, it } from 'vitest';

import { engagementShortcut, initials, unseenIds } from './helpers';

describe('engagementShortcut', () => {
    it('maps bare navigation and action keys', () => {
        expect(engagementShortcut({ key: 'ArrowDown' })).toEqual({
            type: 'next',
        });
        expect(engagementShortcut({ key: 'ArrowUp' })).toEqual({
            type: 'prev',
        });
        expect(engagementShortcut({ key: 'a' })).toEqual({ type: 'archive' });
        expect(engagementShortcut({ key: 'A' })).toEqual({ type: 'archive' });
        expect(engagementShortcut({ key: 'o' })).toEqual({ type: 'open' });
        expect(engagementShortcut({ key: 'O' })).toEqual({ type: 'open' });
        expect(engagementShortcut({ key: 'r' })).toEqual({ type: 'reply' });
        expect(engagementShortcut({ key: 'R' })).toEqual({ type: 'reply' });
    });

    it('ignores modified keys and unrelated keys', () => {
        expect(engagementShortcut({ key: 'a', metaKey: true })).toBeNull();
        expect(
            engagementShortcut({ key: 'ArrowDown', ctrlKey: true }),
        ).toBeNull();
        expect(engagementShortcut({ key: 'j' })).toBeNull();
    });

    it('ignores events from editable fields', () => {
        const textarea = document.createElement('textarea');

        expect(engagementShortcut({ key: 'a', target: textarea })).toBeNull();
    });
});

describe('initials', () => {
    it('reads the reply author fields', () => {
        expect(
            initials({ author_name: 'Ada Lovelace', author_handle: '@ada' }),
        ).toBe('AL');
        expect(initials({ author_name: null, author_handle: '@ada' })).toBe(
            'AD',
        );
        expect(initials({ author_name: null, author_handle: '' })).toBe('?');
    });
});

describe('unseenIds', () => {
    it('reports only rows the reader has not seen yet', () => {
        const onScreen = [{ id: 'a' }, { id: 'b' }];

        expect(unseenIds(onScreen, [{ id: 'c' }, { id: 'a' }])).toEqual(['c']);
        expect(unseenIds(onScreen, onScreen)).toEqual([]);
        expect(unseenIds([], [{ id: 'a' }])).toEqual(['a']);
        // A row disappearing (archived elsewhere) is not something to announce.
        expect(unseenIds(onScreen, [{ id: 'a' }])).toEqual([]);
    });
});

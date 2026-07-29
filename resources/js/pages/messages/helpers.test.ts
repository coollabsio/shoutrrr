/** @vitest-environment jsdom */

import { describe, expect, it } from 'vitest';

import { initials, messagesShortcut, windowLabel } from './helpers';

describe('windowLabel', () => {
    it('returns null when no window', () => {
        expect(windowLabel(null)).toBeNull();
        expect(windowLabel('not-a-date')).toBeNull();
    });

    it('warns when the window expired', () => {
        expect(windowLabel(new Date(Date.now() - 1000).toISOString())).toMatch(
            /closed/i,
        );
    });

    it('counts down when the window is still open', () => {
        const inTwoHours = new Date(
            Date.now() + 2 * 60 * 60 * 1000,
        ).toISOString();

        expect(windowLabel(inTwoHours)).toMatch(/closes in 2h/i);
    });

    it('never counts down to zero while the window is open', () => {
        const inOneMinute = new Date(Date.now() + 60 * 1000).toISOString();

        expect(windowLabel(inOneMinute)).toMatch(/closes in 1h/i);
    });
});

describe('messagesShortcut', () => {
    it('maps bare navigation and action keys', () => {
        expect(messagesShortcut({ key: 'ArrowDown' })).toEqual({
            type: 'next',
        });
        expect(messagesShortcut({ key: 'ArrowUp' })).toEqual({ type: 'prev' });
        expect(messagesShortcut({ key: 'a' })).toEqual({ type: 'archive' });
        expect(messagesShortcut({ key: 'A' })).toEqual({ type: 'archive' });
        expect(messagesShortcut({ key: 'r' })).toEqual({ type: 'reply' });
        expect(messagesShortcut({ key: 'R' })).toEqual({ type: 'reply' });
    });

    it('has no open-in-place shortcut, unlike the engagement inbox', () => {
        // A DM has no permalink to open — `O` must stay unmapped.
        expect(messagesShortcut({ key: 'o' })).toBeNull();
        expect(messagesShortcut({ key: 'O' })).toBeNull();
    });

    it('ignores modified keys and unrelated keys', () => {
        expect(messagesShortcut({ key: 'a', metaKey: true })).toBeNull();
        expect(
            messagesShortcut({ key: 'ArrowDown', ctrlKey: true }),
        ).toBeNull();
        expect(messagesShortcut({ key: 'r', altKey: true })).toBeNull();
        expect(messagesShortcut({ key: 'j' })).toBeNull();
    });

    it('ignores events from editable fields', () => {
        const textarea = document.createElement('textarea');

        expect(messagesShortcut({ key: 'a', target: textarea })).toBeNull();
    });
});

describe('initials', () => {
    it('reads the conversation counterpart fields', () => {
        expect(
            initials({
                counterpart_name: 'Ada Lovelace',
                counterpart_handle: '@ada',
            }),
        ).toBe('AL');
        expect(
            initials({ counterpart_name: null, counterpart_handle: '@ada' }),
        ).toBe('AD');
        expect(
            initials({ counterpart_name: null, counterpart_handle: null }),
        ).toBe('?');
    });
});

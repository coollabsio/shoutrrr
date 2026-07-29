/** @vitest-environment jsdom */

import { describe, expect, it } from 'vitest';

import {
    actionErrorMessage,
    adjacentIndex,
    atHandle,
    initialsFrom,
    isBareShortcut,
    isTypingTarget,
    nextAfterArchive,
    relativeTime,
} from '../helpers';

describe('isTypingTarget', () => {
    it('detects inputs and contenteditable elements', () => {
        expect(isTypingTarget(document.createElement('input'))).toBe(true);
        expect(isTypingTarget(document.createElement('textarea'))).toBe(true);
        expect(isTypingTarget(document.createElement('select'))).toBe(true);

        const editable = document.createElement('div');
        Object.defineProperty(editable, 'isContentEditable', {
            value: true,
        });
        expect(isTypingTarget(editable)).toBe(true);

        expect(isTypingTarget(document.createElement('button'))).toBe(false);
        expect(isTypingTarget(null)).toBe(false);
    });
});

describe('isBareShortcut', () => {
    it('rejects modified keys and events from editable fields', () => {
        expect(isBareShortcut({ key: 'a' })).toBe(true);
        expect(isBareShortcut({ key: 'a', metaKey: true })).toBe(false);
        expect(isBareShortcut({ key: 'a', ctrlKey: true })).toBe(false);
        expect(isBareShortcut({ key: 'a', altKey: true })).toBe(false);
        expect(
            isBareShortcut({ key: 'a', target: document.createElement('div') }),
        ).toBe(true);
        expect(
            isBareShortcut({
                key: 'a',
                target: document.createElement('textarea'),
            }),
        ).toBe(false);
    });
});

describe('adjacentIndex', () => {
    it('clamps within bounds and picks ends when nothing is selected', () => {
        expect(adjacentIndex(0, -1, 1)).toBe(-1);
        expect(adjacentIndex(3, -1, 1)).toBe(0);
        expect(adjacentIndex(3, -1, -1)).toBe(2);
        expect(adjacentIndex(3, 0, -1)).toBe(0);
        expect(adjacentIndex(3, 2, 1)).toBe(2);
        expect(adjacentIndex(3, 1, 1)).toBe(2);
    });
});

describe('nextAfterArchive', () => {
    it('prefers the following item, then the previous, then empty', () => {
        expect(nextAfterArchive(['a', 'b', 'c'], 'b')).toBe('c');
        expect(nextAfterArchive(['a', 'b', 'c'], 'c')).toBe('b');
        expect(nextAfterArchive(['only'], 'only')).toBeNull();
        expect(nextAfterArchive([], 'missing')).toBeNull();
        expect(nextAfterArchive(['a', 'b'], 'missing')).toBe('a');
    });
});

describe('atHandle', () => {
    it('prefixes bare handles and leaves prefixed ones alone', () => {
        expect(atHandle('shoutrrr')).toBe('@shoutrrr');
        expect(atHandle('@shoutrrr')).toBe('@shoutrrr');
        expect(atHandle(null)).toBe('');
        expect(atHandle('')).toBe('');
    });
});

describe('actionErrorMessage', () => {
    it('reads the JSON message and falls back on anything else', () => {
        expect(
            actionErrorMessage(
                { data: JSON.stringify({ message: 'Rate limited.' }) },
                'fallback',
            ),
        ).toBe('Rate limited.');

        // A gateway HTML error page must not throw inside the error handler.
        expect(
            actionErrorMessage(
                { data: '<html>502 Bad Gateway</html>' },
                'oops',
            ),
        ).toBe('oops');
        expect(actionErrorMessage({ data: '{"message":"   "}' }, 'oops')).toBe(
            'oops',
        );
        expect(actionErrorMessage({ data: 'null' }, 'oops')).toBe('oops');
        expect(actionErrorMessage({ data: '{"message":42}' }, 'oops')).toBe(
            'oops',
        );
    });
});

describe('initialsFrom', () => {
    it('takes two initials from a name, else the handle, else a placeholder', () => {
        expect(initialsFrom('Ada Lovelace', null)).toBe('AL');
        expect(initialsFrom('Ada', null)).toBe('AD');
        expect(initialsFrom(null, '@shoutrrr')).toBe('SH');
        expect(initialsFrom(null, null)).toBe('?');
        expect(initialsFrom('   ', null)).toBe('?');
    });
});

describe('relativeTime', () => {
    it('renders a compact age and tolerates missing or invalid input', () => {
        const minutesAgo = (n: number) =>
            new Date(Date.now() - n * 60 * 1000).toISOString();

        expect(relativeTime(minutesAgo(0))).toBe('now');
        expect(relativeTime(minutesAgo(5))).toBe('5m');
        expect(relativeTime(minutesAgo(3 * 60))).toBe('3h');
        expect(relativeTime(minutesAgo(2 * 24 * 60))).toBe('2d');
        // Older than a week drops to a short date rather than a huge day count.
        expect(relativeTime(minutesAgo(30 * 24 * 60))).toMatch(
            /^[A-Z][a-z]{2} \d{1,2}$/,
        );
        expect(relativeTime(null)).toBe('');
        expect(relativeTime('not-a-date')).toBe('');
    });
});

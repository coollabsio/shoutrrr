/** @vitest-environment jsdom */

import { describe, expect, it } from 'vitest';

import {
    adjacentIndex,
    engagementShortcut,
    isTypingTarget,
    nextAfterArchive,
} from './helpers';

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

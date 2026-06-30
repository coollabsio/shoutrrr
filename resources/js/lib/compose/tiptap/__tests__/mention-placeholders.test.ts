import { describe, expect, it } from 'vitest';

import {
    mentionDecorations,
    type MentionDecoration,
} from '@/lib/compose/tiptap/mention-placeholders';

function decorationsIn(text: string, base = 0): MentionDecoration[] {
    return [...mentionDecorations(text, base)];
}

describe('mentionDecorations', () => {
    it('highlights a standalone handle', () => {
        expect(decorationsIn('hi @guest there')).toEqual([
            { start: 3, end: 9, id: 'guest' },
        ]);
    });

    it('highlights a handle at the start of the text', () => {
        expect(decorationsIn('@guest hi')).toEqual([
            { start: 0, end: 6, id: 'guest' },
        ]);
    });

    it('does not highlight the @domain part of an email', () => {
        // Regression: "@example.com" inside an email was highlighted as a handle.
        expect(decorationsIn('user@example.com')).toEqual([]);
        expect(decorationsIn('email me at user@example.com please')).toEqual(
            [],
        );
    });

    it('highlights a real handle even when an email is also present', () => {
        expect(decorationsIn('hi @guest at user@example.com')).toEqual([
            { start: 3, end: 9, id: 'guest' },
        ]);
    });

    it('highlights serialized mention tokens', () => {
        expect(decorationsIn('hi {{mention:guest}}')).toEqual([
            { start: 3, end: 20, id: '-mention-guest-' },
        ]);
    });

    it('offsets ranges by the provided base position', () => {
        expect(decorationsIn('@guest', 5)).toEqual([
            { start: 5, end: 11, id: 'guest' },
        ]);
    });

    it('honors trailing boundary punctuation', () => {
        expect(decorationsIn('hey @guest!')).toEqual([
            { start: 4, end: 10, id: 'guest' },
        ]);
    });
});

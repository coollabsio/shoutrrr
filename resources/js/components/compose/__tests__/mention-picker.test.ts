import { describe, expect, it } from 'vitest';

import {
    mentionFilter,
    savedMentionSearchKeywords,
    shouldFocusMentionPickerSearch,
} from '@/components/compose/mention-picker';

describe('savedMentionSearchKeywords', () => {
    it('includes platform handles without @ prefix', () => {
        expect(
            savedMentionSearchKeywords(
                {
                    id: '1',
                    name: '@saved',
                    handles: {
                        x: '@saved_x',
                        linkedin: 'Saved Name',
                    },
                },
                ['x', 'linkedin'],
            ),
        ).toEqual(['saved_x', 'Saved Name']);
    });
});

describe('mentionFilter', () => {
    it('shows all items when search is empty', () => {
        expect(mentionFilter('@saved', '', ['saved_x'])).toBe(1);
    });

    it('matches mention name and platform handle keywords', () => {
        expect(mentionFilter('@saved', 'saved', ['saved_x'])).toBe(1);
        expect(mentionFilter('@saved', 'saved_x', ['saved_x'])).toBe(1);
        expect(mentionFilter('@saved', 'missing', ['saved_x'])).toBe(0);
    });
});

describe('mention picker focus helpers', () => {
    it('does not select the input while the user is already typing in it', () => {
        const input = {} as HTMLInputElement;

        expect(shouldFocusMentionPickerSearch(input, input)).toBe(false);
        expect(shouldFocusMentionPickerSearch(input, null)).toBe(true);
    });
});

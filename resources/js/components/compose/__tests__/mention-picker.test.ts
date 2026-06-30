import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    editSavedMention,
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

describe('saved mention editing', () => {
    it('loads the saved mention into the editable placeholder shape', () => {
        expect(
            editSavedMention({
                id: 'workspace-mention',
                name: '@saved',
                handles: {
                    x: '@saved_x',
                    bluesky: '@saved.bsky.social',
                    linkedin: 'Saved Name',
                },
            }),
        ).toEqual({
            id: 'saved',
            label: '@saved',
            handles: {
                x: '@saved_x',
                bluesky: '@saved.bsky.social',
                linkedin: 'Saved Name',
            },
        });
    });

    it('renders a dedicated edit action for each saved mention', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/mention-picker.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('aria-label={`Edit ${saved.name}`}');
        expect(source).toContain('pr-9');
        expect(source).toContain('absolute right-2');
        expect(source).toContain('editSaved(saved)');
    });
});

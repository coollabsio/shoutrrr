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

describe('linkedin mention field', () => {
    const source = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/compose/mention-picker.tsx',
        ),
        'utf8',
    );

    it('exposes a plain-text ⇄ tag toggle for LinkedIn', () => {
        expect(source).toContain('Tag company');
        expect(source).toContain('Plain text');
    });

    it('uses a segmented control that shows both modes at once', () => {
        // Both options are rendered side by side (not one action-labeled
        // button) so the active mode is always visible.
        expect(source).toContain('MentionModeToggle');
        expect(source).toContain('role="group"');
        expect(source).toContain('aria-pressed={active}');
    });

    it('holds each platform mode in local state so clearing cannot flip it', () => {
        // The '@' prefix, toggle, and placeholder all read one local state var
        // instead of re-deriving the mode from the handle on every keystroke.
        expect(source).toContain('function PlatformMentionField');
        expect(source).toContain('setUseMention');
        expect(source).toContain('supportsMention && useMention');
    });

    it('labels mention-incapable platforms as plain text instead of leaving them bare', () => {
        expect(source).toContain('supportsMention ? (');
    });

    it('confirms a linked company instead of showing dev jargon', () => {
        expect(source).toContain('Company linked');
        expect(source).not.toContain('php artisan');
    });

    it('auto-detects a pasted reference via extractLinkedInOrgRef', () => {
        expect(source).toContain('extractLinkedInOrgRef');
    });

    it('resets its local tag state per mention via a keyed element', () => {
        expect(source).toContain('key={`linkedin-${activeMention.id}`}');
    });

    it('drops the standalone company-URL/URN input', () => {
        expect(source).not.toContain('LinkedIn company URL or org URN');
    });
});

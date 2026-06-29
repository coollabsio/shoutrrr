import { describe, expect, it } from 'vitest';

import {
    createMention,
    mentionInputValue,
    mentionToken,
    replaceMentionLabel,
    replaceMentionTokens,
    setPlatformMentionMode,
    updateMentionHandle,
    updateMentionName,
    syncMentionsFromText,
    usesPlatformMention,
    type MentionPlaceholder,
} from '../mentions';

describe('replaceMentionLabel', () => {
    it('renames a whole mention token', () => {
        expect(
            replaceMentionLabel('hi @guest there', '@guest', '@guest2'),
        ).toBe('hi @guest2 there');
        expect(
            replaceMentionLabel('@guest and @guest', '@guest', '@member'),
        ).toBe('@member and @member');
    });

    it('renames the in-progress "@" without rewriting other handles', () => {
        // Regression: a naive replaceAll("@", "@member") corrupted "@guest".
        expect(replaceMentionLabel('@guest @', '@', '@member')).toBe(
            '@guest @member',
        );
    });

    it('leaves the label alone when it is only part of a longer token', () => {
        expect(replaceMentionLabel('@guest', '@', '@member')).toBe('@guest');
        expect(replaceMentionLabel('email@guest', '@guest', '@member')).toBe(
            'email@guest',
        );
    });

    it('honors trailing boundary punctuation', () => {
        expect(replaceMentionLabel('hey @guest!', '@guest', '@member')).toBe(
            'hey @member!',
        );
    });

    it('is a no-op for an empty or unchanged label', () => {
        expect(replaceMentionLabel('@guest', '', '@member')).toBe('@guest');
        expect(replaceMentionLabel('@guest', '@guest', '@guest')).toBe(
            '@guest',
        );
    });
});

describe('mention helpers', () => {
    it('creates mention metadata from a typed handle', () => {
        const mention = createMention('Guest');

        expect(mention.label).toBe('@Guest');
        expect(mention.handles).toEqual({
            x: '@Guest',
            bluesky: '@Guest',
            linkedin: 'Guest',
        });
        expect(mentionToken(mention.id)).toBe(`{{mention:${mention.id}}}`);
    });

    it('stores a different handle per platform', () => {
        const mention: MentionPlaceholder = {
            id: 'guest',
            label: '@guest',
            handles: { x: '@old' },
        };

        const updated = updateMentionHandle(
            mention,
            'bluesky',
            '@guest.bsky.social',
        );

        expect(updated.handles).toEqual({
            x: '@old',
            bluesky: '@guest.bsky.social',
        });
    });

    it('preadds @ when a platform handle is typed without it', () => {
        const mention: MentionPlaceholder = {
            id: 'guest',
            label: '@guest',
            handles: {},
        };

        expect(updateMentionHandle(mention, 'x', 'guest_x').handles.x).toBe(
            '@guest_x',
        );
    });

    it('can store plain display text for a platform instead of an @ mention', () => {
        const mention: MentionPlaceholder = {
            id: 'guest',
            label: '@guest',
            handles: { linkedin: '@guest' },
        };

        const text = updateMentionHandle(
            mention,
            'linkedin',
            'Guest LinkedIn',
            false,
        );

        expect(text.handles.linkedin).toBe('Guest LinkedIn');
        expect(usesPlatformMention(text, 'linkedin')).toBe(false);
        expect(
            replaceMentionTokens('Hi {{mention:guest}}', [text], 'linkedin'),
        ).toBe('Hi Guest LinkedIn');
    });

    it('toggles a non-LinkedIn platform between @ mention and display text', () => {
        const mention: MentionPlaceholder = {
            id: 'guest',
            label: '@guest',
            handles: { x: '@guest' },
        };

        const text = setPlatformMentionMode(mention, 'x', false);
        const atMention = setPlatformMentionMode(text, 'x', true);

        expect(text.handles.x).toBe('guest');
        expect(atMention.handles.x).toBe('@guest');
    });

    it('keeps generated LinkedIn text in sync while the mention name changes', () => {
        const mention: MentionPlaceholder = {
            id: 'm',
            label: '@m',
            handles: {
                x: '@m',
                bluesky: '@m',
                linkedin: 'm',
            },
        };

        const updated = updateMentionName(mention, 'misterfdsfsfds');

        expect(updated.handles).toEqual({
            x: '@misterfdsfsfds',
            bluesky: '@misterfdsfsfds',
            linkedin: 'misterfdsfsfds',
        });
    });

    it('forces LinkedIn values to text only', () => {
        const mention: MentionPlaceholder = {
            id: 'guest',
            label: '@guest',
            handles: { linkedin: '@guest' },
        };

        const updated = updateMentionHandle(
            mention,
            'linkedin',
            '@guest',
            true,
        );
        const toggled = setPlatformMentionMode(updated, 'linkedin', true);

        expect(updated.handles.linkedin).toBe('guest');
        expect(toggled.handles.linkedin).toBe('guest');
        expect(usesPlatformMention(toggled, 'linkedin')).toBe(false);
    });

    it('shows mention inputs without the permanent @ prefix', () => {
        expect(mentionInputValue('@guest')).toBe('guest');
        expect(mentionInputValue('guest')).toBe('guest');
    });

    it('replaces tokens with the active platform handle when publishing text is prepared', () => {
        const mentions: MentionPlaceholder[] = [
            {
                id: 'guest',
                label: '@guest',
                handles: { x: '@guest_x', linkedin: '@GuestLinkedIn' },
            },
        ];

        expect(
            replaceMentionTokens('Hi {{mention:guest}}', mentions, 'x'),
        ).toBe('Hi @guest_x');
        expect(
            replaceMentionTokens('Hi {{mention:guest}}', mentions, 'linkedin'),
        ).toBe('Hi GuestLinkedIn');
    });
});

describe('syncMentionsFromText', () => {
    it('creates mention metadata when a handle is typed in the text', () => {
        const mentions = syncMentionsFromText('Hello @guest', []);

        expect(mentions).toEqual([
            {
                id: 'guest',
                label: '@guest',
                handles: {
                    x: '@guest',
                    bluesky: '@guest',
                    linkedin: 'guest',
                },
            },
        ]);
    });

    it('creates mention metadata as soon as @ is typed', () => {
        const mentions = syncMentionsFromText('Hello @', []);

        expect(mentions).toEqual([
            {
                id: expect.any(String),
                label: '@',
                handles: {
                    x: '@',
                    bluesky: '@',
                    linkedin: '',
                },
            },
        ]);
    });

    it('turns a just-opened @ mention into the typed handle', () => {
        const [mention] = syncMentionsFromText('Hello @', []);
        const mentions = syncMentionsFromText('Hello @guest', [mention]);

        expect(mentions).toEqual([
            {
                id: 'guest',
                label: '@guest',
                handles: {
                    x: '@guest',
                    bluesky: '@guest',
                    linkedin: 'guest',
                },
            },
        ]);
    });

    it('removes mention metadata when the handle is deleted from the text', () => {
        const mentions = syncMentionsFromText('Hello there', [
            {
                id: 'guest',
                label: '@guest',
                handles: { x: '@guest_x' },
            },
        ]);

        expect(mentions).toEqual([]);
    });

    it('keeps custom platform handles when a typed mention is still present', () => {
        const mentions = syncMentionsFromText('Hello @guest', [
            {
                id: 'guest',
                label: '@guest',
                handles: { x: '@guest_x', bluesky: '@guest.bsky.social' },
            },
        ]);

        expect(mentions[0].handles).toEqual({
            x: '@guest_x',
            bluesky: '@guest.bsky.social',
        });
    });
});

describe('saved workspace mentions', () => {
    it('uses saved handles when a typed mention name matches', () => {
        const mentions = syncMentionsFromText(
            'Hello @saved',
            [],
            [
                {
                    id: 'saved-id',
                    name: '@saved',
                    handles: { x: '@saved_x' },
                },
            ],
        );

        expect(mentions).toEqual([
            {
                id: 'saved',
                label: '@saved',
                handles: { x: '@saved_x' },
            },
        ]);
    });
});

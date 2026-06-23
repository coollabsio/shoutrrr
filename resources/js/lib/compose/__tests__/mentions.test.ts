import { describe, expect, it } from 'vitest';

import {
    createMention,
    mentionInputValue,
    mentionToken,
    replaceMentionTokens,
    updateMentionHandle,
    syncMentionsFromText,
    type MentionPlaceholder,
} from '../mentions';

describe('mention helpers', () => {
    it('creates mention metadata from a typed handle', () => {
        const mention = createMention('Guest');

        expect(mention.label).toBe('@Guest');
        expect(mention.handles).toEqual({
            x: '@Guest',
            bluesky: '@Guest',
            linkedin: '@Guest',
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
        ).toBe('Hi @GuestLinkedIn');
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
                    linkedin: '@guest',
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
                    linkedin: '@',
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
                    linkedin: '@guest',
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

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { LinkedText, linkedTextParts } from './linked-text';

describe('linkedTextParts', () => {
    it('marks bare domains as links with an https href', () => {
        expect(linkedTextParts('Read shoutrrr.com now')).toEqual([
            { type: 'text', text: 'Read ' },
            {
                type: 'link',
                text: 'shoutrrr.com',
                href: 'https://shoutrrr.com',
            },
            { type: 'text', text: ' now' },
        ]);
    });

    it('keeps trailing punctuation outside of the link', () => {
        expect(linkedTextParts('Read shoutrrr.com.')).toEqual([
            { type: 'text', text: 'Read ' },
            {
                type: 'link',
                text: 'shoutrrr.com',
                href: 'https://shoutrrr.com',
            },
            { type: 'text', text: '.' },
        ]);
    });

    it('links X mentions to the matching user profile', () => {
        expect(linkedTextParts('Thanks @actual_person!', 'x')).toEqual([
            { type: 'text', text: 'Thanks ' },
            {
                type: 'link',
                text: '@actual_person',
                href: 'https://x.com/actual_person',
            },
            { type: 'text', text: '!' },
        ]);
    });

    it('links Bluesky mentions to the matching profile', () => {
        expect(
            linkedTextParts('Thanks @actual-person.bsky.social!', 'bluesky'),
        ).toEqual([
            { type: 'text', text: 'Thanks ' },
            {
                type: 'link',
                text: '@actual-person.bsky.social',
                href: 'https://bsky.app/profile/actual-person.bsky.social',
            },
            { type: 'text', text: '!' },
        ]);
    });

    it('links Instagram mentions to the matching profile', () => {
        expect(linkedTextParts('cc @studio.harbor here', 'instagram')).toEqual([
            { type: 'text', text: 'cc ' },
            {
                type: 'link',
                text: '@studio.harbor',
                href: 'https://www.instagram.com/studio.harbor/',
            },
            { type: 'text', text: ' here' },
        ]);
    });

    it('trims a trailing period back out of an Instagram mention', () => {
        expect(linkedTextParts('thanks @harbor.', 'instagram')).toEqual([
            { type: 'text', text: 'thanks ' },
            {
                type: 'link',
                text: '@harbor',
                href: 'https://www.instagram.com/harbor/',
            },
            { type: 'text', text: '.' },
        ]);
    });

    it('links Instagram hashtags to the explore page, lowercasing the href', () => {
        expect(linkedTextParts('Golden hour #Harbor', 'instagram')).toEqual([
            { type: 'text', text: 'Golden hour ' },
            {
                type: 'link',
                text: '#Harbor',
                href: 'https://www.instagram.com/explore/tags/harbor',
            },
        ]);
    });

    it('links Facebook hashtags but leaves @ text alone', () => {
        expect(
            linkedTextParts('Thanks @someone for #LaunchDay', 'facebook'),
        ).toEqual([
            { type: 'text', text: 'Thanks @someone for ' },
            {
                type: 'link',
                text: '#LaunchDay',
                href: 'https://www.facebook.com/hashtag/launchday',
            },
        ]);
    });

    it('does not treat hashtags as links on platforms without them', () => {
        expect(linkedTextParts('post #nope', 'x')).toEqual([
            { type: 'text', text: 'post #nope' },
        ]);
    });

    it('links Threads mentions to the matching profile', () => {
        expect(linkedTextParts('cc @studio.harbor here', 'threads')).toEqual([
            { type: 'text', text: 'cc ' },
            {
                type: 'link',
                text: '@studio.harbor',
                href: 'https://www.threads.net/@studio.harbor',
            },
            { type: 'text', text: ' here' },
        ]);
    });

    it('links Threads hashtags to the tag search, lowercasing the href', () => {
        expect(linkedTextParts('Golden hour #Harbor', 'threads')).toEqual([
            { type: 'text', text: 'Golden hour ' },
            {
                type: 'link',
                text: '#Harbor',
                href: 'https://www.threads.net/search?q=harbor&serp_type=tags',
            },
        ]);
    });

    it('keeps a URL fragment intact instead of splitting off an inner hashtag', () => {
        expect(linkedTextParts('see example.com/#top', 'instagram')).toEqual([
            { type: 'text', text: 'see ' },
            {
                type: 'link',
                text: 'example.com/#top',
                href: 'https://example.com/#top',
            },
        ]);
    });

    it('keeps excluded bare domains as text while linking other URLs', () => {
        expect(
            linkedTextParts('hello shoutrrr.com heyandras.dev', undefined, [
                'heyandras.dev',
            ]),
        ).toEqual([
            { type: 'text', text: 'hello ' },
            {
                type: 'link',
                text: 'shoutrrr.com',
                href: 'https://shoutrrr.com',
            },
            { type: 'text', text: ' heyandras.dev' },
        ]);
    });

    it('renders preview links with visible link styling', () => {
        const markup = renderToStaticMarkup(
            createElement(LinkedText, { text: 'Read shoutrrr.com' }),
        );

        expect(markup).toContain('href="https://shoutrrr.com"');
        expect(markup).toContain('underline');
        expect(markup).toContain('text-primary');
    });
});

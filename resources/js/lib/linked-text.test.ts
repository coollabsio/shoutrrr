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

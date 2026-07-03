import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import type { ReplyItem } from '../types';
import { ReplyThread } from './reply-thread';

const platforms: ReplyItem['platform'][] = ['x', 'bluesky', 'linkedin'];

function render(platform: ReplyItem['platform']): string {
    return renderToStaticMarkup(
        createElement(ReplyThread, {
            postExcerpt: 'hello, this is just a test',
            postUrl: 'https://example.com/post',
            platform,
            thread: [],
            loading: false,
            onToggleLike: vi.fn(),
            onDelete: vi.fn(),
        }),
    );
}

describe('ReplyThread', () => {
    it('labels the source post link with the platform name', () => {
        expect(render('x')).toContain('Open post on X');
        expect(render('bluesky')).toContain('Open post on Bluesky');
        expect(render('linkedin')).toContain('Open post on LinkedIn');
    });

    it.each(platforms)(
        'does not render the generic open post label for %s',
        (platform) => {
            expect(render(platform)).not.toContain('>Open post<');
        },
    );
});

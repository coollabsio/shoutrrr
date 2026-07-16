import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { ReplyThread } from './reply-thread';

function render(): string {
    return renderToStaticMarkup(
        createElement(ReplyThread, {
            postExcerpt: 'hello, this is just a test',
            thread: [],
            loading: false,
            onToggleLike: vi.fn(),
            onDelete: vi.fn(),
        }),
    );
}

describe('ReplyThread', () => {
    it('shows the your-post excerpt without a platform open link', () => {
        const html = render();

        expect(html).toContain('Your post');
        expect(html).toContain('hello, this is just a test');
        expect(html).not.toContain('Open post on');
    });

    it('keeps long thread content scrollable without growing the pane', () => {
        const source = readFileSync(
            resolve(import.meta.dirname, 'reply-thread.tsx'),
            'utf8',
        );

        expect(source).toContain(
            'min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto p-4',
        );
        expect(source).toContain('break-words whitespace-pre-wrap');
        expect(source).not.toContain('postUrl');
    });
});

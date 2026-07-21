import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import type { ReplyItem } from '../types';
import { ReplyThread } from './reply-thread';

function reply(overrides: Partial<ReplyItem> = {}): ReplyItem {
    return {
        id: 'reply-1',
        platform: 'x',
        remote_reply_id: 'r1',
        author_handle: 'someone',
        author_name: 'Some One',
        author_avatar_url: null,
        text: 'nice post',
        remote_created_at: new Date().toISOString(),
        is_read: true,
        is_liked: false,
        can_like: true,
        is_ours: false,
        send_status: null,
        status: 'pending',
        post_target_id: 'pt-1',
        post_id: 'p-1',
        post_remote_id: 'pr-1',
        post_excerpt: null,
        account_handle: '@us',
        account_max_text_length: null,
        account_disabled: false,
        ...overrides,
    };
}

function render(thread: ReplyItem[] = []): string {
    return renderToStaticMarkup(
        createElement(ReplyThread, {
            postExcerpt: 'hello, this is just a test',
            thread,
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

    it('disables the like button on platforms that cannot like replies', () => {
        const markup = render([
            reply({ platform: 'linkedin', can_like: false }),
        ]);

        // The attribute, not the `disabled:` Tailwind variants, which are
        // always in the class list.
        expect(markup).toContain('disabled=""');
        expect(markup).toContain('LinkedIn does not support liking replies');
    });

    it('leaves the like button enabled when the platform supports liking', () => {
        const markup = render([reply({ can_like: true })]);

        expect(markup).not.toContain('disabled=""');
        expect(markup).not.toContain('does not support liking replies');
    });
});

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { QUICK_REPLY_SEND_SHORTCUT, QuickReplyBox } from './quick-reply-box';

vi.mock('./use-reply-media', () => ({
    useReplyMedia: () => ({
        chips: null,
        dropHandlers: {},
        editor: null,
        fileInput: null,
        isUploading: false,
        openFilePicker: vi.fn(),
    }),
}));

describe('QUICK_REPLY_SEND_SHORTCUT', () => {
    it('shows both supported send shortcuts', () => {
        expect(QUICK_REPLY_SEND_SHORTCUT).toBe('⌘/Ctrl↵');
    });

    it('renders the shortcut on the reply button', () => {
        const html = renderToStaticMarkup(
            createElement(QuickReplyBox, {
                replyId: 'reply-1',
                platform: 'bluesky',
                onSend: async () => {},
            }),
        );

        expect(html).not.toContain('to send');
        expect(html).toContain(QUICK_REPLY_SEND_SHORTCUT);
        expect(html).toContain('data-slot="kbd"');
        expect(html).toContain('sm:inline-flex');
    });
});

it('blurs the reply field on Escape so triage shortcuts work again', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'quick-reply-box.tsx'),
        'utf8',
    );

    expect(source).toContain("e.key === 'Escape'");
    expect(source).toContain('e.currentTarget.blur()');
});

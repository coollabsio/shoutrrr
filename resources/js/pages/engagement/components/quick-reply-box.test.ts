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
    it('shows command enter as the only send shortcut', () => {
        expect(QUICK_REPLY_SEND_SHORTCUT).toBe('⌘↵');
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
        expect(html).toContain(
            `<span>Reply</span><kbd aria-hidden="true" class="ml-0.5 hidden h-4 items-center rounded border border-primary-foreground/25 bg-primary-foreground/15 px-1 font-mono text-[10px] leading-none font-normal text-primary-foreground/90 sm:inline-flex">${QUICK_REPLY_SEND_SHORTCUT}</kbd>`,
        );
    });
});

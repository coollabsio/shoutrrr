import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { QuickMessageBox } from './quick-message-box';

/** The `<textarea data-slot="textarea" ...>` opening tag, in isolation. */
function textareaTag(html: string): string {
    const match = /<textarea[^>]*>/.exec(html);
    if (!match) {
        throw new Error('textarea not found in rendered output');
    }
    return match[0];
}

describe('QuickMessageBox', () => {
    it('enables the textarea when the conversation can reply', () => {
        const html = renderToStaticMarkup(
            createElement(QuickMessageBox, {
                canReply: true,
                onSend: async () => {},
            }),
        );

        expect(textareaTag(html)).not.toContain('disabled=""');
        expect(html).not.toContain('Reply window closed');
    });

    it('disables the textarea and send button when the reply window is closed', () => {
        const html = renderToStaticMarkup(
            createElement(QuickMessageBox, {
                canReply: false,
                onSend: async () => {},
            }),
        );

        expect(html).toContain(
            'Reply window closed — you can only reply within 24h on Instagram/Facebook.',
        );
        expect(textareaTag(html)).toContain('disabled=""');
        expect(html).toContain('data-slot="button"');
    });
});

import type * as InertiaReact from '@inertiajs/react';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import type { PlatformName } from '../types';
import { QuickMessageBox } from './quick-message-box';

vi.mock('@/hooks/compose/use-attachments', () => ({
    useAttachments: () => ({
        chips: null,
        dropHandlers: {},
        editor: null,
        fileInput: null,
        handleAddedFiles: vi.fn(),
        isUploading: false,
        openFilePicker: vi.fn(),
        attachGif: vi.fn(),
    }),
}));

const shellProps = vi.hoisted(() => ({ gifs_enabled: false }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        usePage: () => ({ props: { shell: shellProps } }),
    };
});

/** The `<textarea data-slot="textarea" ...>` opening tag, in isolation. */
function textareaTag(html: string): string {
    const match = /<textarea[^>]*>/.exec(html);
    if (!match) {
        throw new Error('textarea not found in rendered output');
    }

    return match[0];
}

function renderMessageBox(
    overrides: {
        canReply?: boolean;
        platform?: PlatformName;
        shell?: { gifs_enabled?: boolean };
    } = {},
) {
    Object.assign(shellProps, { gifs_enabled: false }, overrides.shell);

    return renderToStaticMarkup(
        createElement(QuickMessageBox, {
            conversationId: 'conversation-1',
            platform: overrides.platform ?? 'x',
            canReply: overrides.canReply ?? true,
            onSend: async () => {},
        }),
    );
}

describe('QuickMessageBox', () => {
    it('enables the textarea when the conversation can reply', () => {
        const html = renderMessageBox();

        expect(textareaTag(html)).not.toContain('disabled=""');
        expect(html).not.toContain('Reply window closed');
    });

    it('disables the textarea and send button when the reply window is closed', () => {
        const html = renderMessageBox({ canReply: false });

        expect(html).toContain(
            'Reply window closed — you can only reply within 24h on Instagram/Facebook.',
        );
        expect(textareaTag(html)).toContain('disabled=""');
        expect(html).toContain('data-slot="button"');
    });
});

describe('QuickMessageBox attachment affordances', () => {
    it.each<PlatformName>(['x', 'instagram', 'facebook'])(
        'shows the attach and GIF buttons on %s',
        (platform) => {
            const html = renderMessageBox({
                platform,
                shell: { gifs_enabled: true },
            });

            expect(html).toContain('Attach photo or video');
            expect(html).toContain('Insert a GIF, sticker or clip');
        },
    );

    // Absent, not disabled: Bluesky DMs have no media embed at all.
    it('hides the attach and GIF buttons on bluesky', () => {
        const html = renderMessageBox({
            platform: 'bluesky',
            shell: { gifs_enabled: true },
        });

        expect(html).not.toContain('Attach photo or video');
        expect(html).not.toContain('Insert a GIF, sticker or clip');
    });

    it('keeps the emoji button on every platform, media or not', () => {
        for (const platform of [
            'x',
            'bluesky',
            'instagram',
            'facebook',
        ] as PlatformName[]) {
            expect(renderMessageBox({ platform })).toContain('Insert emoji');
        }
    });

    it('hides the GIF button when gifs are disabled instance-wide', () => {
        const html = renderMessageBox({
            platform: 'x',
            shell: { gifs_enabled: false },
        });

        expect(html).toContain('Attach photo or video');
        expect(html).not.toContain('Insert a GIF, sticker or clip');
    });

    it('hides the attach affordances when the reply window is closed', () => {
        const html = renderMessageBox({
            platform: 'instagram',
            canReply: false,
            shell: { gifs_enabled: true },
        });

        expect(html).not.toContain('Attach photo or video');
        expect(html).not.toContain('Insert a GIF, sticker or clip');
    });
});

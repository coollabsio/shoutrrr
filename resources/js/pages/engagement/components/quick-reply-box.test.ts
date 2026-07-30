import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import type * as InertiaReact from '@inertiajs/react';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import type { PlatformName } from '@/types/compose';

import { QUICK_REPLY_SEND_SHORTCUT, QuickReplyBox } from './quick-reply-box';

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

function renderReplyBox(
    overrides: {
        shell?: { gifs_enabled?: boolean };
        platform?: PlatformName;
    } = {},
) {
    Object.assign(shellProps, { gifs_enabled: false }, overrides.shell);

    return renderToStaticMarkup(
        createElement(QuickReplyBox, {
            replyId: 'reply-1',
            platform: overrides.platform ?? 'bluesky',
            onSend: async () => {},
        }),
    );
}

describe('QUICK_REPLY_SEND_SHORTCUT', () => {
    it('shows both supported send shortcuts', () => {
        expect(QUICK_REPLY_SEND_SHORTCUT).toBe('⌘/Ctrl↵');
    });

    it('renders the shortcut on the reply button', () => {
        const html = renderReplyBox();

        expect(html).not.toContain('to send');
        expect(html).toContain(QUICK_REPLY_SEND_SHORTCUT);
        expect(html).toContain('data-slot="kbd"');
        expect(html).toContain('sm:inline-flex');
    });
});

describe('QuickReplyBox GIF button', () => {
    it('shows the GIF button when gifs are enabled', () => {
        const html = renderReplyBox({ shell: { gifs_enabled: true } });

        expect(html).toContain('aria-label="Insert a GIF, sticker or clip"');
    });

    it('hides the GIF button when gifs are disabled', () => {
        const html = renderReplyBox({ shell: { gifs_enabled: false } });

        expect(html).not.toContain(
            'aria-label="Insert a GIF, sticker or clip"',
        );
    });
});

/**
 * Only X and Bluesky accept media on a reply (Platform::supportsReplyMedia()).
 */
describe('QuickReplyBox attach affordances by platform', () => {
    const attachLabel = 'aria-label="Attach photo or video"';
    const gifLabel = 'aria-label="Insert a GIF, sticker or clip"';
    const emojiLabel = 'aria-label="Insert emoji"';

    it.each<PlatformName>(['x', 'bluesky'])(
        'shows attach and GIF on %s',
        (platform) => {
            const html = renderReplyBox({
                platform,
                shell: { gifs_enabled: true },
            });

            expect(html).toContain(attachLabel);
            expect(html).toContain(gifLabel);
        },
    );

    it.each<PlatformName>(['linkedin', 'facebook', 'instagram', 'threads'])(
        'hides attach and GIF on %s',
        (platform) => {
            const html = renderReplyBox({
                platform,
                shell: { gifs_enabled: true },
            });

            expect(html).not.toContain(attachLabel);
            expect(html).not.toContain(gifLabel);
        },
    );

    it.each<PlatformName>(['x', 'bluesky', 'linkedin', 'threads'])(
        'always keeps the emoji button on %s',
        (platform) => {
            const html = renderReplyBox({ platform });

            expect(html).toContain(emojiLabel);
        },
    );
});

it('blurs the reply field on Escape so triage shortcuts work again', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'quick-reply-box.tsx'),
        'utf8',
    );

    expect(source).toContain("e.key === 'Escape'");
    expect(source).toContain('editorRef.current?.blur()');
});

import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { EditorBodyHandle } from '@/components/compose/editor-body';
import { RightPane } from '@/pages/engagement/index';
import type { ReplyItem } from '@/pages/engagement/types';

const post = vi.hoisted(() => {
    // jsdom has no matchMedia, and a page import reads it on load.
    window.matchMedia = () =>
        ({
            matches: false,
            addEventListener: () => {},
            removeEventListener: () => {},
        }) as unknown as MediaQueryList;

    return vi.fn();
});

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

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        usePage: () => ({ props: { shell: { gifs_enabled: false } } }),
        useHttp: () => ({ post, transform: vi.fn() }),
    };
});

// jsdom has no Range layout; ProseMirror reads it when scrolling to the cursor.
Range.prototype.getClientRects = () => [] as unknown as DOMRectList;
Range.prototype.getBoundingClientRect = () => ({}) as DOMRect;

const selected: ReplyItem = {
    id: 'reply-1',
    platform: 'x',
    remote_reply_id: 'R1',
    author_handle: 'fan_one',
    author_name: 'fan_one',
    author_avatar_url: null,
    text: 'nice thread',
    remote_created_at: '2026-11-01T12:00:00+00:00',
    is_read: true,
    is_liked: false,
    can_like: false,
    is_ours: false,
    send_status: null,
    status: 'pending',
    post_target_id: 'target-1',
    post_id: 'post-1',
    post_remote_id: 'MEDIA1',
    post_excerpt: 'my post',
    account_handle: 'me',
    account_max_text_length: 500,
    account_disabled: false,
};

async function sendDraft(): Promise<HTMLElement> {
    const editorRef = createRef<EditorBodyHandle>();
    const { container } = render(
        <RightPane
            selected={selected}
            onArchived={vi.fn()}
            onResponded={vi.fn()}
            replyEditorRef={editorRef}
            savedMentions={[]}
        />,
    );
    const editor = () => container.querySelector('.ProseMirror') as HTMLElement;

    await vi.waitFor(() => expect(editor()).not.toBeNull());
    await act(async () => {
        editorRef.current?.insertText('Thanks for the feedback!');
    });
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: /^reply/i }));
    });
    await vi.waitFor(() => expect(post).toHaveBeenCalled());

    return editor();
}

describe('RightPane send', () => {
    beforeEach(() => {
        post.mockReset();
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                json: async () => ({ post_excerpt: null, thread: [] }),
            })),
        );
    });

    it('keeps the draft when the server rejects the reply with a 422', async () => {
        // useHttp fires onError and resolves with nothing on a 422.
        post.mockImplementation(async (_url, options) => {
            options.onError({ text: 'The text is too long.' });
        });

        const editor = await sendDraft();

        await act(async () => {});
        expect(editor.textContent).toBe('Thanks for the feedback!');
    });

    it('clears the draft when the reply is accepted', async () => {
        const reply = { ...selected, id: 'reply-2', is_ours: true };

        post.mockImplementation(async (_url, options) => {
            options.onSuccess({ reply });

            return { reply };
        });

        const editor = await sendDraft();

        await act(async () => {});
        expect(editor.textContent).toBe('');
    });
});

import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { EditorBodyHandle } from '@/components/compose/editor-body';
import {
    QuickReplyBox,
    type ReplyDraftHandle,
} from '@/pages/engagement/components/quick-reply-box';
import type { MediaView } from '@/types/compose';

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
    };
});

// jsdom has no Range layout; ProseMirror reads it when scrolling to the cursor.
Range.prototype.getClientRects = () => [] as unknown as DOMRectList;
Range.prototype.getBoundingClientRect = () => ({}) as DOMRect;

describe('QuickReplyBox send', () => {
    it('clears the editor after a reply is sent', async () => {
        const onSend = vi.fn(async () => true);
        const editorRef = createRef<EditorBodyHandle>();
        const { container } = render(
            <QuickReplyBox
                replyId="reply-1"
                platform="x"
                editorRef={editorRef}
                onSend={onSend}
            />,
        );
        const editor = () =>
            container.querySelector('.ProseMirror') as HTMLElement;

        await vi.waitFor(() => expect(editor()).not.toBeNull());
        await act(async () => {
            editorRef.current?.insertText('Thanks for the feedback!');
        });
        expect(editor().textContent).toBe('Thanks for the feedback!');

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: /reply/i }));
        });

        expect(onSend).toHaveBeenCalledWith('Thanks for the feedback!', [], []);
        expect(editor().textContent).toBe('');
    });

    it('keeps the draft when a send fails', async () => {
        const onSend = vi.fn(async () => false);
        const editorRef = createRef<EditorBodyHandle>();
        const { container } = render(
            <QuickReplyBox
                replyId="reply-1"
                platform="threads"
                editorRef={editorRef}
                onSend={onSend}
            />,
        );
        const editor = () =>
            container.querySelector('.ProseMirror') as HTMLElement;

        await vi.waitFor(() => expect(editor()).not.toBeNull());
        await act(async () => {
            editorRef.current?.insertText('Thanks for the feedback!');
        });

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: /reply/i }));
        });
        expect(editor().textContent).toBe('Thanks for the feedback!');
        expect(screen.getByRole('button', { name: /reply/i })).toBeEnabled();
    });

    it('restores text and attachments from a failed reply', async () => {
        const onSend = vi.fn(async () => true);
        const editorRef = createRef<EditorBodyHandle>();
        const restoreRef = createRef<ReplyDraftHandle>();
        const { container } = render(
            <QuickReplyBox
                replyId="reply-1"
                platform="x"
                editorRef={editorRef}
                restoreRef={restoreRef}
                onSend={onSend}
            />,
        );
        await vi.waitFor(() =>
            expect(container.querySelector('.ProseMirror')).not.toBeNull(),
        );

        await act(async () => {
            restoreRef.current?.restore('Thanks!', [
                { id: 'media-1' } as MediaView,
            ]);
        });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: /reply/i }));
        });

        expect(onSend).toHaveBeenCalledWith(
            'Thanks!',
            ['media-1'],
            [{ id: 'media-1' }],
        );
    });
});

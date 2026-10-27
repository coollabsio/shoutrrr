import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { EditorBodyHandle } from '@/components/compose/editor-body';

import { QuickReplyBox } from './quick-reply-box';

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

describe('QuickReplyBox send', () => {
    it('clears the editor after a reply is sent', async () => {
        const onSend = vi.fn(async () => {});
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

        expect(onSend).toHaveBeenCalledWith('Thanks for the feedback!', []);
        expect(editor().textContent).toBe('');
    });
});

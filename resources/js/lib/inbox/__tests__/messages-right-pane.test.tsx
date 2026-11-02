import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { RightPane } from '@/pages/messages/index';
import type { ConversationItem } from '@/pages/messages/types';

const { post, toastError } = vi.hoisted(() => {
    // jsdom has no matchMedia, and a page import reads it on load.
    window.matchMedia = () =>
        ({
            matches: false,
            addEventListener: () => {},
            removeEventListener: () => {},
        }) as unknown as MediaQueryList;

    return { post: vi.fn(), toastError: vi.fn() };
});

vi.mock('sonner', () => ({ toast: { error: toastError } }));

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

const selected: ConversationItem = {
    id: 'conversation-1',
    platform: 'x',
    counterpart_handle: 'fan_one',
    counterpart_name: 'Fan One',
    counterpart_avatar_url: null,
    last_message_preview: 'hello',
    last_message_at: '2026-11-01T12:00:00+00:00',
    unread_count: 0,
    is_archived: false,
    can_reply: true,
    window_expires_at: null,
    account_handle: 'me',
};

async function sendDraft(): Promise<HTMLTextAreaElement> {
    render(
        <RightPane
            selected={selected}
            onArchived={vi.fn()}
            onResponded={vi.fn()}
        />,
    );
    const field = screen.getByRole('textbox') as HTMLTextAreaElement;

    fireEvent.change(field, { target: { value: 'Thanks for writing!' } });
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: /^(send|reply)/i }));
    });
    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve));
    });

    return field;
}

describe('RightPane send', () => {
    const unhandledRejection = vi.fn();

    beforeEach(() => {
        post.mockReset();
        toastError.mockReset();
        unhandledRejection.mockReset();
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                json: async () => ({ messages: [], data: [] }),
            })),
        );

        return () => {
            process.off('unhandledRejection', unhandledRejection);
        };
    });

    it('keeps the draft and shows the reason on a 422', async () => {
        // useHttp fires onError and resolves with nothing on a 422.
        post.mockImplementation(async (_url, options) => {
            options.onError({ text: 'The text is too long.' });
        });

        const field = await sendDraft();

        expect(field.value).toBe('Thanks for writing!');
        expect(toastError).toHaveBeenCalledWith('The text is too long.');
    });

    it('keeps the draft and handles the rejection on a failed send', async () => {
        process.on('unhandledRejection', unhandledRejection);
        post.mockImplementation(async (_url, options) => {
            options.onHttpException({ data: '{"message":"Could not send."}' });
            throw new Error('Request failed with status 502');
        });

        const field = await sendDraft();

        expect(field.value).toBe('Thanks for writing!');
        expect(unhandledRejection).not.toHaveBeenCalled();
    });

    it('clears the draft when the message is accepted', async () => {
        const message = {
            id: 'message-1',
            remote_message_id: 'R1',
            direction: 'outbound',
            text: 'Thanks for writing!',
            attachments: [],
            remote_created_at: '2026-11-01T12:00:00+00:00',
            is_ours: true,
            send_status: 'sent',
        };

        post.mockImplementation(async (_url, options) => {
            options.onSuccess({ message });

            return { message };
        });

        const field = await sendDraft();

        expect(field.value).toBe('');
    });
});

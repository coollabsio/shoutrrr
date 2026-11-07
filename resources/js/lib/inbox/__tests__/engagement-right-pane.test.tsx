import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { EditorBodyHandle } from '@/components/compose/editor-body';
import {
    REPLY_POLL_LIMIT,
    REPLY_POLL_MS,
    RightPane,
} from '@/pages/engagement/index';
import type { ReplyItem } from '@/pages/engagement/types';

const { post, toastError, toastWarning } = vi.hoisted(() => {
    // jsdom has no matchMedia, and a page import reads it on load.
    window.matchMedia = () =>
        ({
            matches: false,
            addEventListener: () => {},
            removeEventListener: () => {},
        }) as unknown as MediaQueryList;

    return { post: vi.fn(), toastError: vi.fn(), toastWarning: vi.fn() };
});

vi.mock('sonner', () => ({
    toast: { error: toastError, warning: toastWarning },
}));

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
    send_error: null,
    status: 'pending',
    post_target_id: 'target-1',
    post_id: 'post-1',
    post_remote_id: 'MEDIA1',
    post_excerpt: 'my post',
    account_handle: 'me',
    account_max_text_length: 500,
    account_disabled: false,
};

const onResponded = vi.fn();

async function sendDraft(): Promise<HTMLElement> {
    const editorRef = createRef<EditorBodyHandle>();
    const { container } = render(
        <RightPane
            selected={selected}
            onArchived={vi.fn()}
            onResponded={onResponded}
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
        toastError.mockReset();
        toastWarning.mockReset();
        onResponded.mockReset();
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

    describe('a reply sent in the background', () => {
        const queued: ReplyItem = {
            ...selected,
            id: 'reply-2',
            is_ours: true,
            send_status: 'sending',
        };

        function respondWithThread(reply: Partial<ReplyItem>): void {
            vi.stubGlobal(
                'fetch',
                vi.fn(async () => ({
                    json: async () => ({
                        post_excerpt: null,
                        thread: [{ ...queued, ...reply }],
                    }),
                })),
            );
        }

        async function sendAndPoll(): Promise<HTMLElement> {
            post.mockImplementation(async (_url, options) => {
                options.onSuccess({ reply: queued });

                return { reply: queued };
            });
            vi.useFakeTimers({
                toFake: ['setInterval', 'clearInterval'],
            });

            const editor = await sendDraft();

            await act(async () => {});
            expect(editor.textContent).toBe('');

            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS);
            });
            await act(async () => {});

            return editor;
        }

        afterEach(() => {
            vi.useRealTimers();
        });

        it('shows the reason and restores the draft when the job fails', async () => {
            const editor = await sendAndPoll().then(async (field) => {
                respondWithThread({
                    send_status: 'failed',
                    send_error: 'Reply not allowed',
                });
                await act(async () => {
                    vi.advanceTimersByTime(REPLY_POLL_MS);
                });
                await act(async () => {});

                return field;
            });

            expect(toastError).toHaveBeenCalledExactlyOnceWith(
                'Reply not allowed',
            );
            expect(editor.textContent).toBe('Thanks for the feedback!');
        });

        it('marks the conversation replied only after the job sends', async () => {
            await sendAndPoll();
            expect(onResponded).not.toHaveBeenCalled();

            respondWithThread({ send_status: 'sent' });
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS);
            });
            await act(async () => {});

            expect(onResponded).toHaveBeenCalledExactlyOnceWith(selected.id);
        });

        it('does not mark the conversation replied when the job fails', async () => {
            await sendAndPoll();

            respondWithThread({ send_status: 'failed' });
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS);
            });
            await act(async () => {});

            expect(onResponded).not.toHaveBeenCalled();
        });

        it('says the reply is still sending when it stops checking', async () => {
            await sendAndPoll();
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS * REPLY_POLL_LIMIT);
            });
            const polls = vi.mocked(fetch).mock.calls.length;
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS * 3);
            });

            expect(vi.mocked(fetch).mock.calls.length).toBe(polls);
            expect(toastWarning).toHaveBeenCalledOnce();
        });

        it('keeps a failed draft out of another conversation', async () => {
            const other: ReplyItem = { ...selected, id: 'reply-9' };
            const editorRef = createRef<EditorBodyHandle>();
            post.mockImplementation(async (_url, options) => {
                options.onSuccess({ reply: queued });

                return { reply: queued };
            });
            vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
            const props = {
                onArchived: vi.fn(),
                onResponded,
                replyEditorRef: editorRef,
                savedMentions: [],
            };
            const { container, rerender } = render(
                <RightPane selected={selected} {...props} />,
            );
            const editor = () =>
                container.querySelector('.ProseMirror') as HTMLElement;
            await vi.waitFor(() => expect(editor()).not.toBeNull());
            await act(async () => {
                editorRef.current?.insertText('First reply');
            });
            await act(async () => {
                fireEvent.click(
                    screen.getByRole('button', { name: /^reply/i }),
                );
            });
            await act(async () => {});

            // A poll for the first conversation answers after the switch.
            let answerPoll: (value: unknown) => void = () => {};
            vi.stubGlobal(
                'fetch',
                vi.fn(
                    () =>
                        new Promise((resolve) => {
                            answerPoll = resolve;
                        }),
                ),
            );
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS);
            });
            const stalePoll = answerPoll;
            rerender(<RightPane selected={other} {...props} />);
            await vi.waitFor(() => expect(editor()).not.toBeNull());
            await act(async () => {
                stalePoll({
                    json: async () => ({
                        post_excerpt: null,
                        thread: [{ ...queued, send_status: 'failed' }],
                    }),
                });
            });
            await act(async () => {});

            expect(editor().textContent).toBe('');
        });

        it('stops polling once the job has sent the reply', async () => {
            await sendAndPoll();
            respondWithThread({ send_status: 'sent' });
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS);
            });
            await act(async () => {});
            const polls = vi.mocked(fetch).mock.calls.length;

            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS * 3);
            });

            expect(vi.mocked(fetch).mock.calls.length).toBe(polls);
            expect(toastError).not.toHaveBeenCalled();
        });

        it('leaves a new draft alone when the job fails', async () => {
            const editorRef = createRef<EditorBodyHandle>();
            post.mockImplementation(async (_url, options) => {
                options.onSuccess({ reply: queued });

                return { reply: queued };
            });
            vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
            const { container } = render(
                <RightPane
                    selected={selected}
                    onArchived={vi.fn()}
                    onResponded={vi.fn()}
                    replyEditorRef={editorRef}
                    savedMentions={[]}
                />,
            );
            const editor = () =>
                container.querySelector('.ProseMirror') as HTMLElement;

            await vi.waitFor(() => expect(editor()).not.toBeNull());
            await act(async () => {
                editorRef.current?.insertText('First reply');
            });
            await act(async () => {
                fireEvent.click(
                    screen.getByRole('button', { name: /^reply/i }),
                );
            });
            await act(async () => {});
            await act(async () => {
                editorRef.current?.insertText('A newer draft');
            });
            respondWithThread({ send_status: 'failed' });
            await act(async () => {
                vi.advanceTimersByTime(REPLY_POLL_MS);
            });
            await act(async () => {});

            expect(editor().textContent).toBe('A newer draft');
            expect(toastError).toHaveBeenCalledWith(
                'Could not send the reply.',
            );
        });
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

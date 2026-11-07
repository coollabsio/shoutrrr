import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { ScheduleTray } from '@/lib/compose/composer-state';

import { SubmitBar } from '../submit-bar';

const { post, put, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { error: toastError } }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        Link: ({ children }: { children: React.ReactNode }) => (
            <a>{children}</a>
        ),
        useHttp: () => ({ post, put, transform: vi.fn(), processing: false }),
    };
});

const revert = vi.fn();
const unhandledRejection = vi.fn();

async function submit(
    tray: ScheduleTray,
    {
        postId = 'post-1',
        onSaveDraft = async () => true,
        onEnsurePost = async () => 'post-1',
    }: {
        postId?: string | null;
        onSaveDraft?: () => Promise<boolean>;
        onEnsurePost?: () => Promise<string>;
    } = {},
): Promise<void> {
    render(
        <SubmitBar
            tray={tray}
            postId={postId}
            onSaveDraft={onSaveDraft}
            onEnsurePost={onEnsurePost}
            onOptimisticSubmit={() => revert}
            onServerPost={vi.fn()}
            blockedAccounts={[]}
            limits={[]}
        />,
    );
    await act(async () => {
        fireEvent.click(
            screen.getByRole('button', {
                name: /publish now|add to queue|^schedule/i,
            }),
        );
    });
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve));
    });
}

// useHttp fires onError and resolves with nothing on a 422.
function validationFailure(mock: typeof post): void {
    mock.mockImplementation(async (_url, options) => {
        options.onError({ scheduled_at: 'Pick a future time.' });
    });
}

function httpFailure(mock: typeof post, status: number, body: object): void {
    mock.mockImplementation(async (_url, options) => {
        options.onHttpException({ status, data: JSON.stringify(body) });
        throw new Error(`Request failed with status ${status}`);
    });
}

describe('SubmitBar failures', () => {
    beforeEach(() => {
        post.mockReset();
        put.mockReset();
        revert.mockReset();
        toastError.mockReset();
        unhandledRejection.mockReset();
        process.on('unhandledRejection', unhandledRejection);

        return () => {
            process.off('unhandledRejection', unhandledRejection);
        };
    });

    it('does not publish when the save fails', async () => {
        await submit(
            { mode: 'now', pickedAt: null },
            { onSaveDraft: async () => false },
        );

        expect(post).not.toHaveBeenCalled();
        expect(toastError).toHaveBeenCalledWith(
            'Could not save the post, so it was not submitted.',
        );
    });

    it('tells the user when a new post cannot be created', async () => {
        await submit(
            { mode: 'now', pickedAt: null },
            { postId: null, onEnsurePost: async () => '' },
        );

        expect(post).not.toHaveBeenCalled();
        expect(toastError).toHaveBeenCalledWith(
            'Could not save the post, so it was not submitted.',
        );
    });

    it('reverts and explains a past schedule time', async () => {
        validationFailure(put);

        await submit({ mode: 'pick', pickedAt: '2020-01-01T00:00:00Z' });

        expect(revert).toHaveBeenCalled();
        expect(screen.getByText(/already passed/i)).toBeInTheDocument();
    });

    it('reverts and explains a full queue', async () => {
        httpFailure(post, 409, { message: 'No open posting slot available.' });

        await submit({ mode: 'queue', pickedAt: null });

        expect(revert).toHaveBeenCalled();
        expect(screen.getByText(/no open slot/i)).toBeInTheDocument();
        expect(unhandledRejection).not.toHaveBeenCalled();
    });

    it('reverts and lists accounts the server blocks', async () => {
        httpFailure(post, 409, {
            message: "Some accounts can't be published yet.",
            blocked: [
                {
                    account_id: 'account-1',
                    handle: '@acme',
                    platform: 'instagram',
                    issues: ['media_required'],
                },
            ],
        });

        await submit({ mode: 'now', pickedAt: null });

        expect(revert).toHaveBeenCalled();
        expect(screen.getByText('@acme')).toBeInTheDocument();
        expect(unhandledRejection).not.toHaveBeenCalled();
    });

    it('reverts and shows the reason for any other failure', async () => {
        httpFailure(post, 503, { message: 'Try again later.' });

        await submit({ mode: 'now', pickedAt: null });

        expect(revert).toHaveBeenCalled();
        expect(toastError).toHaveBeenCalledWith('Try again later.');
        expect(unhandledRejection).not.toHaveBeenCalled();
    });
});

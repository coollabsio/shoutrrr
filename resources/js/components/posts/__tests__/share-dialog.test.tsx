import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ShareDialog } from '../share-dialog';

const { get, post, destroy, toastError } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { error: toastError, success: vi.fn() } }));

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ get, post, delete: destroy, transform: vi.fn() }),
}));

const unhandledRejection = vi.fn();

async function settle(): Promise<void> {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve));
    });
}

describe('ShareDialog failures', () => {
    beforeEach(() => {
        get.mockReset().mockResolvedValue([]);
        post.mockReset();
        destroy.mockReset();
        toastError.mockReset();
        unhandledRejection.mockReset();
        process.on('unhandledRejection', unhandledRejection);

        return () => {
            process.off('unhandledRejection', unhandledRejection);
        };
    });

    async function createLink(): Promise<void> {
        render(<ShareDialog postId="post-1" open onOpenChange={vi.fn()} />);
        await settle();

        fireEvent.click(screen.getByRole('button', { name: 'Create link' }));
        await settle();
    }

    it('shows the reason a link is rejected on a 422', async () => {
        // useHttp fires onError and resolves with nothing on a 422.
        post.mockImplementation(async (_url, options) => {
            options.onError({ expires_at: 'Pick a future date.' });
        });

        await createLink();

        expect(toastError).toHaveBeenCalledWith('Pick a future date.');
    });

    it('shows the reason a link request fails', async () => {
        post.mockImplementation(async (_url, options) => {
            options.onHttpException({ data: '{"message":"Not allowed."}' });
            throw new Error('Request failed with status 403');
        });

        await createLink();

        expect(toastError).toHaveBeenCalledWith('Not allowed.');
        expect(unhandledRejection).not.toHaveBeenCalled();
    });

    it('survives a list that fails to load', async () => {
        get.mockReset().mockImplementation(async (_url, options) => {
            options.onHttpException({ data: 'Bad gateway' });
            throw new Error('Request failed with status 502');
        });

        render(<ShareDialog postId="post-1" open onOpenChange={vi.fn()} />);
        await settle();

        expect(toastError).toHaveBeenCalledWith('Could not load the links.');

        expect(
            screen.getByRole('button', { name: 'Create link' }),
        ).toBeEnabled();
        expect(unhandledRejection).not.toHaveBeenCalled();
    });
});

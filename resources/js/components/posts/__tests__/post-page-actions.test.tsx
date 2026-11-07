import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PostView } from '@/types/compose';

import { PostPageActions } from '../post-page-actions';

const { put, post, toastError } = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { error: toastError } }));

vi.mock('@/components/common/confirm-dialog', () => ({
    useConfirm: () => async () => true,
}));

vi.mock('@/hooks/posts/use-scheduling-timezone', () => ({
    useSchedulingTimezone: () => 'UTC',
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        router: { visit: vi.fn() },
        usePage: () => ({ props: {} }),
        useHttp: () => ({ put, post, transform: vi.fn(), processing: false }),
    };
});

const scheduled = {
    id: 'post-1',
    status: 'scheduled',
    scheduled_at: '2099-01-01T00:00:00Z',
    targets: [],
} as unknown as PostView;

const failed = {
    id: 'post-1',
    status: 'failed',
    scheduled_at: null,
    targets: [{ id: 'target-1', status: 'failed' }],
} as unknown as PostView;

const unhandledRejection = vi.fn();

async function settle(): Promise<void> {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve));
    });
}

async function saveReschedule(): Promise<void> {
    render(<PostPageActions post={scheduled} />);
    fireEvent.click(screen.getByRole('button', { name: 'Reschedule' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await settle();
}

describe('PostPageActions failures', () => {
    beforeEach(() => {
        put.mockReset();
        post.mockReset();
        toastError.mockReset();
        unhandledRejection.mockReset();
        process.on('unhandledRejection', unhandledRejection);

        return () => {
            process.off('unhandledRejection', unhandledRejection);
        };
    });

    it('keeps the reschedule form open and explains a 422', async () => {
        // useHttp fires onError and resolves with nothing on a 422.
        put.mockImplementation(async (_url, options) => {
            options.onError({ scheduled_at: 'Pick a future time.' });
        });

        await saveReschedule();

        expect(
            screen.getByRole('button', { name: 'Save' }),
        ).toBeInTheDocument();
        expect(toastError).toHaveBeenCalledWith('Pick a future time.');
    });

    it('keeps the reschedule form open when the request fails', async () => {
        put.mockImplementation(async (_url, options) => {
            options.onHttpException({ data: '{"message":"Not allowed."}' });
            throw new Error('Request failed with status 403');
        });

        await saveReschedule();

        expect(
            screen.getByRole('button', { name: 'Save' }),
        ).toBeInTheDocument();
        expect(toastError).toHaveBeenCalledWith('Not allowed.');
        expect(unhandledRejection).not.toHaveBeenCalled();
    });

    it('closes the reschedule form on success', async () => {
        put.mockImplementation(async (_url, options) => {
            options.onSuccess?.({ post: scheduled });

            return { post: scheduled };
        });

        await saveReschedule();

        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    });

    it('reports a retry that fails', async () => {
        post.mockImplementation(async (_url, options) => {
            options.onNetworkError(new Error('offline'));
            throw new Error('offline');
        });
        render(<PostPageActions post={failed} />);

        fireEvent.click(screen.getByRole('button', { name: 'Retry failed' }));
        await settle();

        expect(toastError).toHaveBeenCalledWith('Could not reach the server.');
        expect(unhandledRejection).not.toHaveBeenCalled();
    });
});

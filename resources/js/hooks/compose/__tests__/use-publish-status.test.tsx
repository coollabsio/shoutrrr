import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PostView } from '@/types/compose';

import { usePublishStatus } from '../use-publish-status';

const { post, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { error: toastError } }));

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ post }),
}));

const pagePost = { id: 'post-1', targets: [] } as unknown as PostView;

describe('usePublishStatus retry', () => {
    beforeEach(() => {
        post.mockReset();
        toastError.mockReset();
    });

    it('keeps the snapshot and shows the reason on a 422', async () => {
        // useHttp fires onError and resolves with nothing on a 422.
        post.mockImplementation(async (_url, options) => {
            options.onError({ target: 'That target cannot be retried.' });
        });
        const { result } = renderHook(() => usePublishStatus({ pagePost }));

        await act(async () => {
            await result.current.retry('target-1');
        });

        expect(result.current.snapshot).toBe(pagePost);
        expect(result.current.retryingIds.size).toBe(0);
        expect(toastError).toHaveBeenCalledWith(
            'That target cannot be retried.',
        );
    });

    it('keeps the snapshot and shows the reason on a failed request', async () => {
        post.mockImplementation(async (_url, options) => {
            options.onHttpException({ data: '{"message":"Not retryable."}' });
            throw new Error('Request failed with status 409');
        });
        const { result } = renderHook(() => usePublishStatus({ pagePost }));

        await act(async () => {
            await result.current.retry('target-1');
        });

        expect(result.current.snapshot).toBe(pagePost);
        expect(result.current.retryingIds.size).toBe(0);
        expect(toastError).toHaveBeenCalledWith('Not retryable.');
    });
});

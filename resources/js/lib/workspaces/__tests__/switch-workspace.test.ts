import { beforeEach, describe, expect, it, vi } from 'vitest';

import { switchWorkspace } from '../switch-workspace';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

vi.mock('@/routes/workspaces', () => ({
    switchMethod: {
        url: () => '/workspaces/switch',
    },
}));

describe('switchWorkspace', () => {
    beforeEach(() => {
        inertia.post.mockClear();
    });

    it('switches workspace without preserving page state', () => {
        switchWorkspace('workspace-2');

        expect(inertia.post).toHaveBeenCalledWith(
            '/workspaces/switch',
            { workspace_id: 'workspace-2' },
            expect.objectContaining({
                preserveState: false,
            }),
        );
    });

    it('passes through the finish callback', () => {
        const onFinish = vi.fn();

        switchWorkspace('workspace-2', { onFinish });

        const options = inertia.post.mock.calls[0]?.[2];
        if (!options) {
            throw new Error('Expected router.post options');
        }
        options.onFinish?.({} as never);

        expect(onFinish).toHaveBeenCalledOnce();
    });
});

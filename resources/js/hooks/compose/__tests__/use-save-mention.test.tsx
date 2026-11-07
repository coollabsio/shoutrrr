import { act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { MentionPlaceholder, WorkspaceMention } from '@/types/compose';

import { useSaveMention } from '../use-save-mention';

const { post, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { error: toastError } }));

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ post, transform: vi.fn(), processing: false }),
}));

const existing = {
    id: 'saved-1',
    name: '@zoe',
    handles: {},
} as WorkspaceMention;

const placeholder = {
    label: '@adam',
    handles: { x: '@adam' },
} as unknown as MentionPlaceholder;

function renderSaveMention() {
    return renderHook(() => {
        const [savedMentions, setSavedMentions] = useState([existing]);

        return { savedMentions, ...useSaveMention(setSavedMentions) };
    });
}

describe('useSaveMention', () => {
    beforeEach(() => {
        post.mockReset();
        toastError.mockReset();
    });

    it('reports a 422 and shows the reason, with the list unchanged', async () => {
        // useHttp fires onError and resolves with nothing on a 422.
        post.mockImplementation(async (_url, options) => {
            options.onError({ name: 'That name is not valid.' });
        });
        const { result } = renderSaveMention();

        let saved: boolean | undefined;
        await act(async () => {
            saved = await result.current.saveMention(placeholder);
        });

        expect(saved).toBe(false);

        expect(result.current.savedMentions).toEqual([existing]);
        expect(toastError).toHaveBeenCalledWith('That name is not valid.');
    });

    it('adds the saved mention in name order', async () => {
        const saved = { id: 'saved-2', name: '@adam', handles: {} };

        post.mockResolvedValue({ mention: saved });
        const { result } = renderSaveMention();

        await act(async () => {
            await result.current.saveMention(placeholder);
        });

        expect(result.current.savedMentions).toEqual([saved, existing]);
    });
});

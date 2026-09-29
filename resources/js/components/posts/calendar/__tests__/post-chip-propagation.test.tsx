import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { PostChip } from '@/components/posts/calendar/post-chip';
import type { PostRowData } from '@/components/posts/post-row';
import type { PlatformName } from '@/types/compose';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({
        props: { workspaces: { current: { timezone: 'UTC' } } },
    }),
}));

// Force the chip into the "mid-drag" state so we exercise the drag-end
// synthetic-click branch of openPost.
vi.mock('@dnd-kit/core', () => ({
    useDraggable: () => ({
        attributes: {},
        listeners: {},
        setNodeRef: () => {},
        transform: null,
        isDragging: true,
    }),
}));

function post(): PostRowData {
    return {
        id: 'post-1',
        base_text: 'Existing post',
        status: 'scheduled',
        status_label: 'Scheduled',
        author: null,
        target_count: 1,
        updated_at: '2099-06-15T09:00:00Z',
        scheduled_at: '2099-06-15T14:30:00Z',
        published_at: null,
        platforms: ['x'] as PlatformName[],
        targets: [],
        media_count: 0,
        media_preview: null,
    };
}

describe('PostChip — drag-end click never bubbles to the day cell', () => {
    it('stops propagation even while dragging, so the parent create handler is not triggered', () => {
        const parentClick = vi.fn();
        render(
            <div onClick={parentClick}>
                <PostChip post={post()} draggable />
            </div>,
        );

        fireEvent.click(screen.getByTitle('Existing post'));

        expect(parentClick).not.toHaveBeenCalled();
    });
});

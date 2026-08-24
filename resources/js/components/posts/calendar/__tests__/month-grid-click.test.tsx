import { DndContext } from '@dnd-kit/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { MonthGrid } from '@/components/posts/calendar/month-grid';
import type { PostRowData } from '@/components/posts/post-row';
import { dayjs } from '@/lib/datetime/dayjs';
import type { PlatformName } from '@/types/compose';

const routerVisit = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: { visit: (...a: unknown[]) => routerVisit(...a) },
    usePage: () => ({
        props: { workspaces: { current: { timezone: 'UTC' } } },
        url: '/calendar',
    }),
}));

const anchor = dayjs.utc('2099-06-01');
const dayWithPost = '2099-06-15';

function post(id = 'post-1'): PostRowData {
    return {
        id,
        base_text: 'Existing post',
        status: 'scheduled',
        status_label: 'Scheduled',
        author: null,
        target_count: 1,
        updated_at: `${dayWithPost}T09:00:00Z`,
        scheduled_at: `${dayWithPost}T14:30:00Z`,
        published_at: null,
        platforms: ['x'] as PlatformName[],
        targets: [],
        media_count: 0,
        media_preview: null,
    };
}

function renderGrid(
    onEmptyDayClick: () => void,
    posts: PostRowData[] = [post()],
) {
    return render(
        <DndContext>
            <MonthGrid
                anchor={anchor}
                posts={posts}
                onEmptyDayClick={onEmptyDayClick}
            />
        </DndContext>,
    );
}

describe('MonthGrid — click a day that already has a post', () => {
    it('fires onEmptyDayClick when clicking the cell body of a future day with a post', () => {
        const onEmptyDayClick = vi.fn();
        renderGrid(onEmptyDayClick);

        fireEvent.click(screen.getByLabelText(`Day ${dayWithPost}`));

        expect(onEmptyDayClick).toHaveBeenCalledTimes(1);
    });

    it('fires onEmptyDayClick when clicking a non-button child (date number) of the cell', () => {
        const onEmptyDayClick = vi.fn();
        renderGrid(onEmptyDayClick);

        // The date number span is a nested child; the click must bubble to the cell.
        fireEvent.click(screen.getByText('15'));

        expect(onEmptyDayClick).toHaveBeenCalledTimes(1);
    });

    it('does NOT create a post when clicking the existing post chip (opens the post instead)', () => {
        const onEmptyDayClick = vi.fn();
        renderGrid(onEmptyDayClick);

        fireEvent.click(screen.getByTitle('Existing post'));

        expect(onEmptyDayClick).not.toHaveBeenCalled();
        expect(routerVisit).toHaveBeenCalledTimes(1);
    });

    it('does NOT create a post when activating the "+N more" button with the keyboard', () => {
        const onEmptyDayClick = vi.fn();
        // >3 posts renders the overflow "+N more" <button> inside the cell.
        renderGrid(onEmptyDayClick, [
            post('a'),
            post('b'),
            post('c'),
            post('d'),
        ]);

        const moreButton = screen.getByText(/\+1 more/);
        fireEvent.keyDown(moreButton, { key: 'Enter' });
        fireEvent.keyDown(moreButton, { key: ' ' });

        expect(onEmptyDayClick).not.toHaveBeenCalled();
    });
});

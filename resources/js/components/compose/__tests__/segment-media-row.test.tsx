import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

import { SegmentMediaRow } from '@/components/compose/segment-media-row';
import type { MediaView } from '@/types/compose';

function img(id: string): MediaView {
    return {
        id,
        url: `https://example.test/${id}.jpg`,
        mime: 'image/jpeg',
        kind: 'image',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        edit_url: `https://example.test/${id}.jpg`,
        source_edit_url: null,
    };
}

it('renders a thumbnail per media and fires remove', () => {
    const onRemove = vi.fn();
    render(
        <SegmentMediaRow
            segmentRef="__head__"
            media={[img('m1')]}
            pending={[]}
            onRemove={onRemove}
            onReorder={() => {}}
            onAddClick={() => {}}
        />,
    );

    expect(
        screen.getByRole('button', { name: /media 1/i }),
    ).toBeInTheDocument();

    screen.getByRole('button', { name: /remove/i }).click();
    expect(onRemove).toHaveBeenCalledWith('m1');
});

it('fires onAddClick from the hover paperclip', () => {
    const onAddClick = vi.fn();
    render(
        <SegmentMediaRow
            segmentRef="__head__"
            media={[img('m1')]}
            pending={[]}
            onRemove={() => {}}
            onReorder={() => {}}
            onAddClick={onAddClick}
        />,
    );

    screen.getByRole('button', { name: /add media/i }).click();
    expect(onAddClick).toHaveBeenCalledTimes(1);
});

it('shows the GIF/stickers/clips button next to Add media when an attach handler is supplied', () => {
    render(
        <SegmentMediaRow
            segmentRef="__head__"
            media={[img('m1')]}
            pending={[]}
            onRemove={() => {}}
            onReorder={() => {}}
            onAddClick={() => {}}
            onAttachGif={vi.fn()}
        />,
    );

    expect(
        screen.getByRole('button', { name: /gifs, stickers and clips/i }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: /add media/i }),
    ).toBeInTheDocument();
});

it('hides the GIF button without an attach handler', () => {
    render(
        <SegmentMediaRow
            segmentRef="__head__"
            media={[img('m1')]}
            pending={[]}
            onRemove={() => {}}
            onReorder={() => {}}
            onAddClick={() => {}}
        />,
    );

    expect(
        screen.queryByRole('button', { name: /gifs, stickers and clips/i }),
    ).not.toBeInTheDocument();
});

it('renders nothing when empty, read-only, and not readOnly still exposes the add affordance', () => {
    const { container, rerender } = render(
        <SegmentMediaRow
            segmentRef="__head__"
            media={[]}
            pending={[]}
            readOnly
            onRemove={() => {}}
            onReorder={() => {}}
            onAddClick={() => {}}
        />,
    );
    expect(container).toBeEmptyDOMElement();

    rerender(
        <SegmentMediaRow
            segmentRef="__head__"
            media={[]}
            pending={[]}
            onRemove={() => {}}
            onReorder={() => {}}
            onAddClick={() => {}}
        />,
    );
    expect(
        screen.getByRole('button', { name: /add media/i }),
    ).toBeInTheDocument();
});

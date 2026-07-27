import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ComposerToolbar } from '@/components/compose/composer-toolbar';

const base = {
    autoSplit: true,
    overrideActive: false,
    media: [],
    onRemove: vi.fn(),
    onReorder: vi.fn(),
    onToggleAutoSplit: vi.fn(),
    onToggleOverride: vi.fn(),
    isExcluded: () => false,
    onToggleExclude: vi.fn(),
    pending: [],
    handleFiles: vi.fn(),
    dismissPending: vi.fn(),
    cancelPending: vi.fn(),
    onInsertEmoji: vi.fn(),
    emojiRecents: [],
    emojiSkinTone: 'none' as const,
    onEmojiSkinToneChange: vi.fn(),
};

describe('ComposerToolbar format picker', () => {
    it('shows the format picker for instagram accounts', () => {
        render(
            <ComposerToolbar
                {...base}
                activePlatform="instagram"
                format="feed"
                onFormatChange={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('button', { name: /stories/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /reels/i }),
        ).toBeInTheDocument();
    });

    it('hides the format picker for non-meta accounts', () => {
        render(
            <ComposerToolbar
                {...base}
                activePlatform="x"
                format="feed"
                onFormatChange={vi.fn()}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /stories/i }),
        ).not.toBeInTheDocument();
    });
});

describe('ComposerToolbar GIF button', () => {
    it('shows the GIF button when an attach handler is supplied', () => {
        render(<ComposerToolbar {...base} onAttachGif={vi.fn()} />);
        expect(
            screen.getByRole('button', { name: /gif/i }),
        ).toBeInTheDocument();
    });

    it('hides the GIF button without an attach handler', () => {
        render(<ComposerToolbar {...base} onAttachGif={undefined} />);
        expect(
            screen.queryByRole('button', { name: /gif/i }),
        ).not.toBeInTheDocument();
    });
});

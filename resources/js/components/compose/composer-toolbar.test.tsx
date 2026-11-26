import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ComposerToolbar } from '@/components/compose/composer-toolbar';
import { TooltipProvider } from '@/components/ui/tooltip';

const base = {
    autoSplit: true,
    overrideActive: false,
    media: [],
    onToggleAutoSplit: vi.fn(),
    onToggleOverride: vi.fn(),
    pending: [],
    handleFiles: vi.fn(),
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

describe('ComposerToolbar TikTok post mode', () => {
    it('offers Post and Inbox draft for a TikTok account', () => {
        const onTikTokPostModeChange = vi.fn();
        render(
            <ComposerToolbar
                {...base}
                activePlatform="tiktok"
                tiktokPostMode="direct"
                onTikTokPostModeChange={onTikTokPostModeChange}
                onFormatChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('group', { name: 'How to send to TikTok' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Post' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(
            screen.queryByRole('button', { name: /stories/i }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Inbox draft' }));
        expect(onTikTokPostModeChange).toHaveBeenCalledWith('inbox');
    });

    it('hides the TikTok switch on other platforms and when read-only', () => {
        const { rerender } = render(
            <ComposerToolbar
                {...base}
                activePlatform="instagram"
                onTikTokPostModeChange={vi.fn()}
            />,
        );
        expect(
            screen.queryByRole('group', { name: 'How to send to TikTok' }),
        ).not.toBeInTheDocument();

        rerender(
            <ComposerToolbar
                {...base}
                activePlatform="tiktok"
                readOnly
                onTikTokPostModeChange={vi.fn()}
            />,
        );
        expect(
            screen.queryByRole('group', { name: 'How to send to TikTok' }),
        ).not.toBeInTheDocument();
    });
});

describe('ComposerToolbar media button', () => {
    it('is icon-only but keeps its label and count', () => {
        render(
            <ComposerToolbar
                {...base}
                pending={[
                    {
                        tempId: 't1',
                        kind: 'image',
                        status: 'uploading',
                        segmentRef: '__head__',
                    },
                ]}
            />,
        );

        const button = screen.getByRole('button', { name: 'Add media' });
        expect(button).toHaveTextContent('1');
        expect(button).not.toHaveTextContent(/media/i);
    });
});

describe('ComposerToolbar GIF button', () => {
    it('shows the GIF button when an attach handler is supplied', () => {
        render(<ComposerToolbar {...base} onAttachGif={vi.fn()} />);
        expect(
            screen.getByRole('button', { name: /gif/i }),
        ).toBeInTheDocument();
    });

    // The emoji and GIF triggers are icon-only, so their accessible names come
    // from aria-label rather than visible text.
    it('keeps the icon-only emoji and GIF triggers labelled', () => {
        render(<ComposerToolbar {...base} onAttachGif={vi.fn()} />);

        for (const name of ['Emoji', 'GIFs, stickers and clips']) {
            const button = screen.getByRole('button', { name });
            expect(button).toBeInTheDocument();
            expect(button).toHaveTextContent('');
        }
    });

    it('shows a tooltip covering all three catalogs on hover', async () => {
        // Base UI needs the provider for hover to open a tooltip; the app
        // mounts one app-wide (see app.tsx).
        render(
            <TooltipProvider delay={0}>
                <ComposerToolbar {...base} onAttachGif={vi.fn()} />
            </TooltipProvider>,
        );

        // Base UI binds its hover listeners natively on the trigger, so fire
        // the real enter events rather than React's delegated `over` ones.
        const trigger = screen.getByRole('button', {
            name: /gifs, stickers and clips/i,
        });
        fireEvent.pointerEnter(trigger, { pointerType: 'mouse' });
        fireEvent.mouseEnter(trigger);

        expect(
            await screen.findByText('GIFs, stickers & clips'),
        ).toBeInTheDocument();
    });

    it('hides the GIF button without an attach handler', () => {
        render(<ComposerToolbar {...base} onAttachGif={undefined} />);
        expect(
            screen.queryByRole('button', { name: /gif/i }),
        ).not.toBeInTheDocument();
    });
});

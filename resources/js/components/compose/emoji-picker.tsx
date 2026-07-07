import { EmojiPicker as Frimousse, useSkinTone } from 'frimousse';
import { useEffect } from 'react';

import type { EmojiSkinTone } from '@/lib/compose/emoji/types';

type Props = {
    recents: string[];
    skinTone: EmojiSkinTone;
    onSkinToneChange: (tone: EmojiSkinTone) => void;
    onSelect: (emoji: string) => void;
};

/**
 * Styled Frimousse picker for the composer. Data is self-hosted under `/emoji`
 * (CSP forbids the default CDN). Renders a recents row above the categorized
 * list and persists skin-tone changes through `onSkinToneChange`.
 */
export default function EmojiPicker({
    recents,
    skinTone,
    onSkinToneChange,
    onSelect,
}: Props) {
    return (
        <Frimousse.Root
            className="isolate flex h-[368px] w-full flex-col bg-popover text-popover-foreground"
            locale="en"
            columns={9}
            skinTone={skinTone}
            emojibaseUrl="/emoji"
            onEmojiSelect={({ emoji }) => onSelect(emoji)}
        >
            <SkinTonePersistence onChange={onSkinToneChange} />
            <div className="flex items-center gap-2 px-2 pt-2">
                <Frimousse.Search
                    className="h-8 flex-1 appearance-none rounded-md bg-muted px-2.5 text-sm outline-none placeholder:text-muted-foreground"
                    placeholder="Search emoji…"
                />
                <Frimousse.SkinToneSelector className="flex size-8 items-center justify-center rounded-md text-lg hover:bg-muted" />
            </div>

            {recents.length > 0 && (
                <div className="border-b border-border px-1.5 pt-2 pb-1.5">
                    <div className="px-1.5 pb-1 text-xs font-medium text-muted-foreground">
                        Recent
                    </div>
                    <div className="grid grid-cols-9">
                        {recents.map((emoji, position) => (
                            <button
                                key={`${emoji}-${position}`}
                                type="button"
                                onClick={() => onSelect(emoji)}
                                className="flex h-8 w-full items-center justify-center rounded-md text-lg hover:bg-muted"
                            >
                                {emoji}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <Frimousse.Viewport className="relative flex-1 outline-hidden">
                <Frimousse.Loading className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
                    Loading…
                </Frimousse.Loading>
                <Frimousse.Empty className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
                    No emoji found.
                </Frimousse.Empty>
                <Frimousse.List
                    className="pb-1.5 select-none"
                    components={{
                        CategoryHeader: ({ category, ...props }) => (
                            <div
                                className="bg-popover px-3 pt-3 pb-1.5 text-xs font-medium text-muted-foreground"
                                {...props}
                            >
                                {category.label}
                            </div>
                        ),
                        // Frimousse positions rows absolutely (virtualization) and
                        // styles them `display:flex` inline; override to a full-width
                        // grid so the emoji cells fill the picker instead of packing
                        // left with dead space on the right.
                        Row: ({ children, style, ...props }) => (
                            <div
                                {...props}
                                style={{
                                    ...style,
                                    display: 'grid',
                                    width: '100%',
                                    gridTemplateColumns:
                                        'repeat(9, minmax(0, 1fr))',
                                }}
                                className="scroll-my-1.5 px-1.5"
                            >
                                {children}
                            </div>
                        ),
                        Emoji: ({ emoji, ...props }) => (
                            <button
                                type="button"
                                className="flex h-8 w-full items-center justify-center rounded-md text-lg data-[active]:bg-muted"
                                {...props}
                            >
                                {emoji.emoji}
                            </button>
                        ),
                    }}
                />
            </Frimousse.Viewport>
        </Frimousse.Root>
    );
}

/**
 * Mirrors Frimousse's live skin tone (changed via the in-picker selector) back
 * to the caller so it can be persisted. Must render inside `Frimousse.Root` —
 * `useSkinTone` reads from its store. The sync happens in an effect (not
 * during render) so it never updates the parent while this subtree is
 * rendering.
 */
function SkinTonePersistence({
    onChange,
}: {
    onChange: (tone: EmojiSkinTone) => void;
}) {
    const [skinTone] = useSkinTone();

    useEffect(() => {
        onChange(skinTone);
    }, [skinTone, onChange]);

    return null;
}

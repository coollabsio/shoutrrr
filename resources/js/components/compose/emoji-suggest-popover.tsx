import { type RefObject } from 'react';

import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import type { EmojiMatch } from '@/lib/compose/emoji/types';

import EmojiSuggestList from './emoji-suggest-list';

type Props = {
    open: boolean;
    /** Called by Radix on dismissal (Escape / pointer click away). */
    onDismiss: () => void;
    /** Zero-size element the popover floats beside (pinned to the `:query`). */
    anchorRef: RefObject<HTMLDivElement | null>;
    matches: EmojiMatch[];
    activeIndex: number;
    onSelect: (match: EmojiMatch) => void;
};

/**
 * The inline `:shortcode` typeahead popover. Presentational: all state and
 * keyboard handling live in `useEmojiTypeahead`. Focus stays in the editor
 * (`onOpenAutoFocus` / `onFocusOutside` prevented), so only Escape or a pointer
 * click away — routed through `onOpenChange` — dismisses it.
 */
export default function EmojiSuggestPopover({
    open,
    onDismiss,
    anchorRef,
    matches,
    activeIndex,
    onSelect,
}: Props) {
    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onDismiss();
                }
            }}
        >
            <PopoverAnchor asChild>
                <div
                    ref={anchorRef}
                    aria-hidden
                    className="pointer-events-none absolute w-0"
                />
            </PopoverAnchor>
            <PopoverContent
                align="start"
                side="bottom"
                sideOffset={8}
                className="w-auto rounded-xl p-0"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onFocusOutside={(event) => event.preventDefault()}
            >
                <EmojiSuggestList
                    matches={matches}
                    activeIndex={activeIndex}
                    onSelect={onSelect}
                />
            </PopoverContent>
        </Popover>
    );
}

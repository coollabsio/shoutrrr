import { Popover as PopoverPrimitive } from '@base-ui/react/popover';
import type { ReactElement, ReactNode } from 'react';
import { useState } from 'react';

import { GifPicker } from '@/components/compose/gif-picker';
import { cn } from '@/lib/utils';
import type { GifItem } from '@/types/gifs';

type Props = {
    /** Attach the chosen item. The popover closes itself first. */
    onSelect: (item: GifItem) => void;
    /** Trigger element, supplied by the caller so each surface owns its shape. */
    trigger: (open: boolean) => ReactElement;
    children: ReactNode;
    side?: 'top' | 'bottom';
    align?: 'start' | 'end';
};

/**
 * The GIF picker in a popover. Unlike EmojiPopover this is not kept warm: there
 * is no large dataset to pre-parse, and keeping it mounted would hold a grid of
 * remote images (and autoplaying clip previews) alive behind a closed popover.
 */
export function GifPopover({
    onSelect,
    trigger,
    children,
    side = 'top',
    align = 'start',
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger render={trigger(open)}>
                {children}
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Positioner
                    align={align}
                    side={side}
                    sideOffset={8}
                    className="isolate z-50"
                >
                    <PopoverPrimitive.Popup
                        initialFocus={false}
                        className={cn(
                            'z-50 overflow-hidden rounded-2xl bg-popover text-popover-foreground shadow-lg ring-1 ring-foreground/5 outline-hidden dark:ring-foreground/10',
                            // Same fade+zoom feel as the emoji popover, so the two
                            // sibling buttons read as one family.
                            'origin-(--transform-origin) transition-[opacity,transform] duration-100 ease-out',
                            'data-open:scale-100 data-open:opacity-100',
                            'data-closed:pointer-events-none data-closed:scale-95 data-closed:opacity-0',
                        )}
                    >
                        <GifPicker
                            onSelect={(item) => {
                                setOpen(false);
                                onSelect(item);
                            }}
                        />
                    </PopoverPrimitive.Popup>
                </PopoverPrimitive.Positioner>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}

export default GifPopover;

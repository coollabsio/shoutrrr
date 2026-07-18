import { Popover as PopoverPrimitive } from '@base-ui/react/popover';
import type { ReactElement, ReactNode } from 'react';
import { useEffect, useState } from 'react';

import EmojiPicker from '@/components/compose/emoji-picker';
import type { EmojiSkinTone } from '@/lib/compose/emoji/types';
import { cn } from '@/lib/utils';

type Props = {
    /** Recently-used emoji, newest first. */
    recents: string[];
    skinTone: EmojiSkinTone;
    onSkinToneChange: (tone: EmojiSkinTone) => void;
    /** Insert the chosen emoji. The popover closes itself afterwards. */
    onSelect: (emoji: string) => void;
    /**
     * The trigger element, supplied by the caller so each surface owns its own
     * button shape. Receives the open state, since Base UI leaves it to the
     * trigger to reflect (the composer tints a pill, the reply box does not).
     * Pass a childless element and put its content in `children`, per the
     * Base UI `render` convention used throughout the app.
     */
    trigger: (open: boolean) => ReactElement;
    /** Content of the trigger (icon, label). */
    children: ReactNode;
    side?: 'top' | 'bottom';
    align?: 'start' | 'end';
};

/**
 * The emoji picker in a popover, with no knowledge of what it inserts into —
 * shared by the composer toolbar and the engagement reply box.
 */
export function EmojiPopover({
    recents,
    skinTone,
    onSkinToneChange,
    onSelect,
    trigger,
    children,
    side = 'top',
    align = 'end',
}: Props) {
    const [open, setOpen] = useState(false);
    // Mount the picker once and keep it alive. Frimousse re-reads and re-parses
    // the ~775KB emoji dataset and rebuilds its store on every fresh mount, so
    // unmounting on close (the default) made each reopen — and the select
    // that closes it — sluggish. We warm it during browser idle (never on the
    // click, which the parse would block) and Portal `keepMounted` keeps it
    // alive; when closed it's hidden via a transition, not unmounted.
    const [mounted, setMounted] = useState(false);
    useEffect(() => {
        if (mounted) {
            return;
        }
        if (open) {
            setMounted(true);

            return;
        }
        const idle = window as Window & {
            requestIdleCallback?: (callback: () => void) => number;
            cancelIdleCallback?: (handle: number) => void;
        };
        if (typeof idle.requestIdleCallback === 'function') {
            const handle = idle.requestIdleCallback(() => setMounted(true));

            return () => idle.cancelIdleCallback?.(handle);
        }
        const handle = window.setTimeout(() => setMounted(true), 500);

        return () => window.clearTimeout(handle);
    }, [mounted, open]);

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger render={trigger(open)}>
                {children}
            </PopoverPrimitive.Trigger>
            {mounted && (
                <PopoverPrimitive.Portal keepMounted>
                    <PopoverPrimitive.Positioner
                        align={align}
                        side={side}
                        sideOffset={8}
                        // While closed the kept-warm popover stays mounted and
                        // positioned over the composer; make the positioner
                        // click-through so it doesn't swallow clicks on the tab
                        // strip / controls beneath it.
                        className="isolate z-50 data-closed:pointer-events-none"
                    >
                        <PopoverPrimitive.Popup
                            data-keep-warm=""
                            initialFocus={false}
                            className={cn(
                                'z-50 w-[336px] overflow-hidden rounded-2xl bg-popover text-popover-foreground shadow-lg ring-1 ring-foreground/5 outline-hidden dark:ring-foreground/10',
                                // Same fade+zoom feel as the notification bell, but
                                // driven by a CSS transition instead of a keyframe
                                // animate-in/-out. The picker is kept warm and
                                // prewarmed while closed, so a keyframe `animate-out`
                                // would flash it on that first hidden mount; a
                                // transition only runs on real state changes.
                                // opacity/transform are GPU-composited, so it stays
                                // smooth on the heavy virtualized grid.
                                'origin-(--transform-origin) transition-[opacity,transform] duration-100 ease-out',
                                'data-open:scale-100 data-open:opacity-100',
                                'data-closed:pointer-events-none data-closed:scale-95 data-closed:opacity-0',
                            )}
                        >
                            <EmojiPicker
                                recents={recents}
                                skinTone={skinTone}
                                onSkinToneChange={onSkinToneChange}
                                onSelect={(emoji) => {
                                    onSelect(emoji);
                                    setOpen(false);
                                }}
                            />
                        </PopoverPrimitive.Popup>
                    </PopoverPrimitive.Positioner>
                </PopoverPrimitive.Portal>
            )}
        </PopoverPrimitive.Root>
    );
}

export default EmojiPopover;

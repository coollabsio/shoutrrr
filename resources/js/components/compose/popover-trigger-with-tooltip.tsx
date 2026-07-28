import { Popover as PopoverPrimitive } from '@base-ui/react/popover';
import type { ReactElement, ReactNode } from 'react';

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Props = {
    /** The trigger element, supplied by the surface so it owns its own shape. */
    render: ReactElement;
    /** Content of the trigger (icon, label). */
    children: ReactNode;
    /** Hover/focus label. Without it the trigger renders untouched. */
    tooltip?: ReactNode;
    /** The popover's open state, mirrored into the tooltip. */
    open: boolean;
    side?: 'top' | 'bottom';
};

/**
 * A popover trigger that can carry a tooltip — for the emoji and GIF popovers,
 * whose icon-only triggers have no visible label to explain them.
 *
 * The tooltip is disabled while the popover is open, so hovering the trigger to
 * close the picker doesn't pop a label over it. Must be rendered inside a
 * `Popover.Root`, whose context the underlying trigger reads.
 */
export function PopoverTriggerWithTooltip({
    render,
    children,
    tooltip,
    open,
    side = 'top',
}: Props) {
    if (tooltip === undefined) {
        return (
            <PopoverPrimitive.Trigger render={render}>
                {children}
            </PopoverPrimitive.Trigger>
        );
    }

    return (
        <Tooltip disabled={open}>
            <PopoverPrimitive.Trigger
                render={<TooltipTrigger render={render} />}
            >
                {children}
            </PopoverPrimitive.Trigger>
            <TooltipContent side={side}>{tooltip}</TooltipContent>
        </Tooltip>
    );
}

export default PopoverTriggerWithTooltip;

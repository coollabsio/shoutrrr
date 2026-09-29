'use client';

import { Radio as RadioPrimitive } from '@base-ui/react/radio';
import { RadioGroup as RadioGroupPrimitive } from '@base-ui/react/radio-group';

import { cn } from '@/lib/utils';

function RadioGroup({ className, ...props }: RadioGroupPrimitive.Props) {
    return (
        <RadioGroupPrimitive
            data-slot="radio-group"
            className={cn('grid gap-2', className)}
            {...props}
        />
    );
}

function RadioGroupItem({ className, ...props }: RadioPrimitive.Root.Props) {
    return (
        <RadioPrimitive.Root
            data-slot="radio-group-item"
            className={cn(
                'peer relative flex size-4 shrink-0 items-center justify-center rounded-full border border-transparent bg-input/90 transition-shadow outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 data-checked:border-primary data-checked:bg-primary-gradient data-checked:text-primary-foreground',
                className,
            )}
            {...props}
        >
            <RadioPrimitive.Indicator className="flex items-center justify-center data-unchecked:hidden">
                <span className="size-1.5 rounded-full bg-current" />
            </RadioPrimitive.Indicator>
        </RadioPrimitive.Root>
    );
}

export { RadioGroup, RadioGroupItem };

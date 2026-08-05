import { cn } from '@/lib/utils';

type ShortcutEvent = Pick<
    KeyboardEvent,
    'altKey' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey'
>;

export const commandShortcutListenerOptions = { capture: true } as const;

export function isComposeShortcut(event: ShortcutEvent): boolean {
    return (
        (event.metaKey || event.ctrlKey) &&
        !event.altKey &&
        !event.shiftKey &&
        event.key === '.'
    );
}

export function composeButtonClassName(collapsed: boolean): string {
    return cn(
        'h-9 justify-between gap-2 bg-primary-gradient font-medium text-primary-foreground shadow-sm ring-1 inset-shadow-[0_1px_0_0_var(--primary-gradient-highlight)] ring-primary/20 transition-[all,--primary-gradient-top] select-none',
        'hover:text-primary-foreground hover:shadow hover:[--primary-gradient-top:var(--primary-gradient-highlight)] active:scale-[0.98]',
        'data-active:bg-primary-gradient data-active:text-primary-foreground',
        collapsed && 'justify-center',
    );
}

export function composeIconClassName(): string {
    return 'pointer-events-none flex size-5 items-center justify-center [&>svg]:size-3.5';
}

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
        'h-9 justify-between gap-2 bg-primary font-medium text-primary-foreground shadow-sm ring-1 ring-primary/20 transition-all select-none',
        'hover:bg-primary/90 hover:text-primary-foreground hover:shadow active:scale-[0.98]',
        'data-active:bg-primary data-active:text-primary-foreground',
        collapsed && 'justify-center',
    );
}

export function composeIconClassName(): string {
    return 'pointer-events-none flex size-5 items-center justify-center [&>svg]:size-3.5';
}

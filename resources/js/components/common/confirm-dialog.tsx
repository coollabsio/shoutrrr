import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
} from 'react';
import { flushSync } from 'react-dom';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

type ConfirmOptions = {
    title: string;
    description?: string;
    actionLabel?: string;
    cancelLabel?: string;
    /** Render the confirm button as destructive (red). */
    destructive?: boolean;
};

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * Imperative confirmation dialog. Returns a promise that resolves true if the
 * user confirms, false otherwise.
 *
 * The single dialog lives at the app root (see ConfirmProvider in app.tsx), so a
 * caller that mutates/unmounts itself on confirm never tears down its own modal.
 */
export function useConfirm(): ConfirmFn {
    const confirm = useContext(ConfirmContext);
    if (confirm === null) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }

    return confirm;
}

export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    const resolverRef = useRef<((value: boolean) => void) | null>(null);
    const openFrameRef = useRef<number | null>(null);

    const settle = useCallback((result: boolean) => {
        // Idempotent: double-clicks or repeated Escape presses should only
        // resolve the current confirmation once.
        const resolver = resolverRef.current;
        if (!resolver) {
            return;
        }

        resolverRef.current = null;

        // Force Radix's portal/overlay tree to unmount before the caller runs
        // any Inertia mutation that may remove rows or navigate.
        flushSync(() => setOptions(null));
        resolver(result);
    }, []);

    const confirm = useCallback<ConfirmFn>((opts) => {
        // Abandon any prior pending confirmation before opening a new one.
        resolverRef.current?.(false);
        if (openFrameRef.current !== null) {
            window.cancelAnimationFrame(openFrameRef.current);
            openFrameRef.current = null;
        }

        return new Promise<boolean>((resolve) => {
            if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }

            resolverRef.current = resolve;

            // Post action confirmations are often opened from a Radix
            // DropdownMenu item. During that item callback, the dropdown still
            // owns `body.style.pointerEvents = 'none'`. If AlertDialog mounts in
            // the same tick, Radix captures `'none'` as the original body value
            // and restores it on close, leaving the whole UI unclickable.
            openFrameRef.current = window.requestAnimationFrame(() => {
                openFrameRef.current = null;
                setOptions(opts);
            });
        });
    }, []);

    useEffect(() => {
        return () => {
            if (openFrameRef.current !== null) {
                window.cancelAnimationFrame(openFrameRef.current);
            }
        };
    }, []);

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            {options !== null && (
                <AlertDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            settle(false);
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{options.title}</AlertDialogTitle>
                            {options.description !== undefined && (
                                <AlertDialogDescription>
                                    {options.description}
                                </AlertDialogDescription>
                            )}
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel onClick={() => settle(false)}>
                                {options.cancelLabel ?? 'Cancel'}
                            </AlertDialogCancel>
                            <AlertDialogAction
                                variant={
                                    options.destructive
                                        ? 'destructive'
                                        : 'default'
                                }
                                onClick={() => settle(true)}
                            >
                                {options.actionLabel ?? 'Confirm'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </ConfirmContext.Provider>
    );
}

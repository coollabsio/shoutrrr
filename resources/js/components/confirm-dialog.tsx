import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useRef,
    useState,
} from 'react';

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
 * caller that mutates/unmounts itself on confirm (e.g. an optimistic row
 * removal) never tears down an open modal — which would otherwise strand Radix's
 * `pointer-events: none` on <body> and freeze the page.
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

    const settle = useCallback((result: boolean) => {
        // Idempotent: the confirm button both resolves here and triggers Radix's
        // close → onOpenChange, which would otherwise resolve a second time.
        resolverRef.current?.(result);
        resolverRef.current = null;
        setOptions(null);
    }, []);

    const confirm = useCallback<ConfirmFn>((opts) => {
        // Abandon any prior pending confirmation before opening a new one.
        resolverRef.current?.(false);

        return new Promise<boolean>((resolve) => {
            resolverRef.current = resolve;
            setOptions(opts);
        });
    }, []);

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <AlertDialog
                open={options !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        settle(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{options?.title}</AlertDialogTitle>
                        {options?.description !== undefined && (
                            <AlertDialogDescription>
                                {options.description}
                            </AlertDialogDescription>
                        )}
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => settle(false)}>
                            {options?.cancelLabel ?? 'Cancel'}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant={
                                options?.destructive ? 'destructive' : 'default'
                            }
                            onClick={() => settle(true)}
                        >
                            {options?.actionLabel ?? 'Confirm'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </ConfirmContext.Provider>
    );
}

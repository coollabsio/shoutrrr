import { Popover as PopoverPrimitive } from '@base-ui/react/popover';
import { Link } from '@inertiajs/react';
import { Check, Rocket } from 'lucide-react';
import { useState } from 'react';

import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import type { Account } from '@/types/compose';

/**
 * Per-post boost override. `null` inherits each account's Auto-boost setting
 * (reshare only if the post outperforms), `true` forces the reshare, `false`
 * exempts this post entirely.
 */
export type BoostValue = boolean | null;

type Props = {
    value: BoostValue;
    onChange: (value: BoostValue) => void;
    /** Selected accounts on repost-capable platforms (X, LinkedIn, Bluesky). */
    accounts: Account[];
};

const OPTIONS: { value: BoostValue; label: string; description: string }[] = [
    {
        value: null,
        label: 'Automatic',
        description: 'Boost only if this post takes off',
    },
    {
        value: true,
        label: 'Always',
        description: "Boost even if it doesn't take off",
    },
    {
        value: false,
        label: 'Never',
        description: "Don't boost this post",
    },
];

/**
 * The Boost control in the composer toolbar: a quiet tool button opening a
 * three-way choice over the post's `auto_repost` override, with a footer that
 * names the accounts that will actually reshare — or points to the account
 * setting when none will, so the control is never a silent no-op.
 */
export function BoostPopover({ value, onChange, accounts }: Props) {
    const [open, setOpen] = useState(false);

    // Only accounts with the account-level Auto-boost setting reshare; the
    // per-post override cannot force a boost on an account that has it off.
    const boosting = accounts.filter((a) => a.auto_repost_enabled === true);
    // The label always names the mode; the active tint marks the states that
    // can actually fire (an explicit override, or Automatic with at least one
    // enabled account behind it).
    const autoLive = value === null && boosting.length > 0;
    const label =
        value === true
            ? 'Boost: Always'
            : value === false
              ? 'Boost: Never'
              : 'Boost: Automatic';

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger
                render={
                    <button
                        type="button"
                        title="Auto-boost — reshare this post from your account for a second wave of reach"
                        data-active={open || value !== null || autoLive}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md border border-transparent bg-transparent px-2.5 text-[12px] text-muted-foreground transition-colors sm:h-7',
                            'hover:border-border hover:bg-background hover:text-foreground',
                            'data-[active=true]:border-border data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
                        )}
                    />
                }
            >
                <Rocket className="size-3.5" aria-hidden="true" />
                <span>{label}</span>
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Positioner
                    align="end"
                    side="top"
                    sideOffset={8}
                    className="isolate z-50"
                >
                    <PopoverPrimitive.Popup
                        className={cn(
                            'z-50 w-72 overflow-hidden rounded-2xl bg-popover text-popover-foreground shadow-lg ring-1 ring-foreground/5 outline-hidden dark:ring-foreground/10',
                            'origin-(--transform-origin) duration-100 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
                        )}
                    >
                        <div className="px-4 pt-3 pb-2">
                            <p className="text-[13px] font-medium">
                                Auto-boost
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Reshares this post from your account after it
                                has had time to circulate.
                            </p>
                        </div>
                        <div
                            role="radiogroup"
                            aria-label="Auto-boost"
                            className="flex flex-col gap-0.5 px-1.5 pb-1.5"
                        >
                            {OPTIONS.map((option) => (
                                <button
                                    key={String(option.value)}
                                    type="button"
                                    role="radio"
                                    aria-checked={value === option.value}
                                    onClick={() => {
                                        onChange(option.value);
                                        setOpen(false);
                                    }}
                                    className="flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left transition-colors hover:bg-muted"
                                >
                                    <span className="min-w-0">
                                        <span className="block text-[13px] font-medium">
                                            {option.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {option.description}
                                        </span>
                                    </span>
                                    {value === option.value && (
                                        <Check
                                            className="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                    )}
                                </button>
                            ))}
                        </div>
                        {boosting.length > 0
                            ? value !== false && (
                                  <p className="border-t border-border bg-muted/40 px-4 py-2.5 text-[11px] text-muted-foreground">
                                      Boosts from{' '}
                                      {boosting
                                          .map((a) => a.handle)
                                          .join(' · ')}
                                  </p>
                              )
                            : accounts.length > 0 && (
                                  <p className="border-t border-border bg-muted/40 px-4 py-2.5 text-[11px] text-muted-foreground">
                                      Auto-boost is off for every selected
                                      account.{' '}
                                      <Link
                                          href={accountsRoute()}
                                          className="font-medium text-foreground underline underline-offset-2"
                                      >
                                          Turn it on in Accounts
                                      </Link>
                                  </p>
                              )}
                    </PopoverPrimitive.Popup>
                </PopoverPrimitive.Positioner>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}

export default BoostPopover;

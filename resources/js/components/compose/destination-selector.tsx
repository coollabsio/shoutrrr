import { Check, ChevronDown, Layers } from 'lucide-react';
import type React from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { Account, AccountSet, Destination } from '@/types/compose';

/** Per-platform brand accent for the glyph badge (mirrors the accounts page). */
const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

/**
 * Avatar with the platform logo tucked into the bottom-right corner. The badge
 * stays inside the avatar bounds so it never gets clipped when this visual is
 * mirrored into the (overflow-hidden) trigger.
 */
function AccountVisual({ account }: { account: Account }) {
    const brand = PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;

    return (
        <span className="relative inline-grid shrink-0">
            <Avatar className="size-5">
                <AvatarImage
                    src={account.avatar_url ?? undefined}
                    alt={account.handle}
                />
                <AvatarFallback className="text-[9px] font-medium">
                    {account.handle.replace(/^@/, '').slice(0, 1).toUpperCase()}
                </AvatarFallback>
            </Avatar>
            <span
                className={cn(
                    'absolute right-0 bottom-0 grid size-2.5 place-items-center rounded-full ring-2 ring-popover',
                    brand.tile,
                    brand.glyph,
                )}
            >
                {/* size-* class is required: shared item CSS force-sizes any
                    class-less svg to size-4 (16px). */}
                <PlatformGlyph
                    platform={account.platform}
                    className={cn('size-1.5', brand.glyph)}
                />
            </span>
        </span>
    );
}

/** Leading icon for an account set. */
function SetVisual() {
    return (
        <span className="grid size-5 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
            <Layers className="size-3" />
        </span>
    );
}

type DestinationSelectorProps = {
    accounts: Account[];
    sets: AccountSet[];
    destination: Destination;
    onChange: (destination: Destination) => void;
    /** Lock the selector (read-only post). */
    disabled?: boolean;
};

function selectedAccountIds(
    destination: Destination,
    accounts: Account[],
    sets: AccountSet[],
): string[] {
    if (destination.kind === 'account') {
        return accounts.some((a) => a.id === destination.id)
            ? [destination.id]
            : [];
    }
    if (destination.kind === 'accounts') {
        const available = new Set(accounts.map((a) => a.id));

        return destination.ids.filter((id) => available.has(id));
    }
    if (destination.kind === 'set') {
        return (
            sets.find((s) => s.id === destination.id)?.connected_account_ids ??
            []
        );
    }

    return accounts.map((a) => a.id);
}

function sameIds(left: string[], right: string[]): boolean {
    if (left.length !== right.length) {
        return false;
    }
    const selected = new Set(left);

    return right.every((id) => selected.has(id));
}

function destinationFromIds(
    ids: string[],
    accounts: Account[],
    preferredSet: AccountSet | null = null,
): Destination {
    if (ids.length === accounts.length) {
        return { kind: 'all' };
    }
    if (preferredSet && sameIds(ids, preferredSet.connected_account_ids)) {
        return { kind: 'set', id: preferredSet.id };
    }
    if (ids.length === 1) {
        return { kind: 'account', id: ids[0] };
    }

    return { kind: 'accounts', ids };
}

function triggerLabel(
    destination: Destination,
    selectedIds: string[],
    accounts: Account[],
    sets: AccountSet[],
): string {
    if (selectedIds.length === accounts.length) {
        return 'All accounts';
    }
    if (destination.kind === 'set') {
        return sets.find((s) => s.id === destination.id)?.name ?? 'Set';
    }
    if (selectedIds.length === 1) {
        return (
            accounts.find((a) => a.id === selectedIds[0])?.handle ?? '1 account'
        );
    }

    return `${selectedIds.length} accounts`;
}

export default function DestinationSelector({
    accounts,
    sets,
    destination,
    onChange,
    disabled = false,
}: DestinationSelectorProps) {
    const selectedIds = selectedAccountIds(destination, accounts, sets);
    const selected = new Set(selectedIds);
    const label = triggerLabel(destination, selectedIds, accounts, sets);

    function chooseAll() {
        onChange({ kind: 'all' });
    }

    function toggleAccount(accountId: string) {
        const next = selected.has(accountId)
            ? selectedIds.filter((id) => id !== accountId)
            : [...selectedIds, accountId];

        if (next.length === 0) {
            return;
        }

        onChange(destinationFromIds(next, accounts));
    }

    function toggleSet(set: AccountSet) {
        const allSetAccountsSelected = set.connected_account_ids.every((id) =>
            selected.has(id),
        );
        const setIds = new Set(set.connected_account_ids);
        const next = allSetAccountsSelected
            ? selectedIds.filter((id) => !setIds.has(id))
            : [...new Set([...selectedIds, ...set.connected_account_ids])];

        if (next.length === 0) {
            return;
        }

        onChange(destinationFromIds(next, accounts, set));
    }

    return (
        <Popover>
            <PopoverTrigger asChild disabled={disabled}>
                <button
                    type="button"
                    aria-label="Post destination"
                    className="inline-flex h-7 max-w-[150px] items-center gap-1 rounded-md border border-transparent bg-transparent px-2 text-[12px] text-muted-foreground hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                >
                    <span className="truncate">{label}</span>
                    <ChevronDown className="size-3 shrink-0 opacity-70" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[216px] gap-1 rounded-3xl p-2 text-sm"
            >
                <div className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                    Sets
                </div>
                <OptionButton
                    selected={selectedIds.length === accounts.length}
                    onClick={chooseAll}
                >
                    <SetVisual />
                    All accounts
                </OptionButton>
                {sets.map((set) => (
                    <OptionButton
                        key={set.id}
                        selected={sameIds(
                            selectedIds,
                            set.connected_account_ids,
                        )}
                        onClick={() => toggleSet(set)}
                    >
                        <SetVisual />
                        {set.name}
                    </OptionButton>
                ))}
                {accounts.length > 0 && (
                    <>
                        <div className="px-2 pt-3 pb-1.5 text-xs font-medium text-muted-foreground">
                            Accounts
                        </div>
                        {accounts.map((account) => (
                            <OptionButton
                                key={account.id}
                                selected={selected.has(account.id)}
                                onClick={() => toggleAccount(account.id)}
                            >
                                <AccountVisual account={account} />
                                {account.handle}
                            </OptionButton>
                        ))}
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}

function OptionButton({
    selected,
    children,
    onClick,
}: {
    selected: boolean;
    children: React.ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onClick}
            className="flex min-h-8 w-full items-center gap-2 rounded-xl px-2 py-1.5 text-left text-sm outline-hidden select-none hover:bg-muted focus-visible:bg-muted"
        >
            <span className="flex min-w-0 flex-1 items-center gap-2 truncate">
                {children}
            </span>
            <Check
                className={cn(
                    'ml-auto size-4 shrink-0 text-foreground',
                    selected ? 'opacity-100' : 'opacity-0',
                )}
            />
        </button>
    );
}

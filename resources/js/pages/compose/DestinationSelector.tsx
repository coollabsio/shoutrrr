import { PlatformGlyph } from '@/components/platform-glyph';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import type { Account, AccountSet, Destination } from './types';

type DestinationSelectorProps = {
    accounts: Account[];
    sets: AccountSet[];
    destination: Destination;
    onChange: (destination: Destination) => void;
    /** Lock the selector (read-only post). */
    disabled?: boolean;
};

function toValue(destination: Destination): string {
    if (destination.kind === 'all') {
        return 'all';
    }

    return `${destination.kind}:${destination.id}`;
}

export default function DestinationSelector({
    accounts,
    sets,
    destination,
    onChange,
    disabled = false,
}: DestinationSelectorProps) {
    function handleChange(value: string) {
        if (value === 'all') {
            onChange({ kind: 'all' });

            return;
        }
        const [kind, id] = value.split(':');
        onChange(
            kind === 'set' ? { kind: 'set', id } : { kind: 'account', id },
        );
    }

    return (
        <Select
            value={toValue(destination)}
            onValueChange={handleChange}
            disabled={disabled}
        >
            <SelectTrigger
                size="sm"
                aria-label="Post destination"
                className="max-w-[150px] gap-1 rounded-md border-transparent bg-transparent px-2 text-[12px] text-muted-foreground hover:bg-muted hover:text-foreground data-[size=sm]:h-7"
            >
                <SelectValue placeholder="Choose where to post" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">All accounts</SelectItem>
                {sets.length > 0 && (
                    <SelectGroup>
                        <SelectLabel>Sets</SelectLabel>
                        {sets.map((set) => (
                            <SelectItem key={set.id} value={`set:${set.id}`}>
                                {set.name}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                )}
                {accounts.length > 0 && (
                    <SelectGroup>
                        <SelectLabel>Accounts</SelectLabel>
                        {accounts.map((account) => (
                            <SelectItem
                                key={account.id}
                                value={`account:${account.id}`}
                            >
                                <span className="grid size-[16px] place-items-center rounded-[4px] bg-muted text-foreground">
                                    <PlatformGlyph
                                        platform={account.platform}
                                        size={10}
                                    />
                                </span>
                                {account.handle}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                )}
            </SelectContent>
        </Select>
    );
}

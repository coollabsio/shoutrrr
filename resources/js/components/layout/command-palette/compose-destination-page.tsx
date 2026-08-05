import { router } from '@inertiajs/react';

import { CommandGroup, CommandItem } from '@/components/ui/command';
import { ListChecks, Pencil, Share2 } from '@/components/ui/icons';

interface Account {
    id: string;
    handle: string;
    display_name?: string | null;
    platform: string;
}

interface AccountSet {
    id: string;
    name: string;
}

interface ComposeDestinationPageProps {
    accounts: Account[];
    sets: AccountSet[];
    composeUrl: string;
    run: (fn: () => void) => () => void;
}

export function ComposeDestinationPage({
    accounts,
    sets,
    composeUrl,
    run,
}: ComposeDestinationPageProps) {
    return (
        <CommandGroup heading="Compose for…">
            <CommandItem
                value="compose all"
                onSelect={run(() => router.visit(composeUrl))}
            >
                <Pencil className="size-4" aria-hidden />
                All accounts
            </CommandItem>
            {accounts.map((account) => (
                <CommandItem
                    key={account.id}
                    value={`compose account ${account.handle}`}
                    onSelect={run(() =>
                        router.visit(
                            `${composeUrl}?destination=account:${account.id}`,
                        ),
                    )}
                >
                    <Share2 className="size-4" aria-hidden />
                    <span className="truncate">{account.handle}</span>
                </CommandItem>
            ))}
            {sets.map((set) => (
                <CommandItem
                    key={set.id}
                    value={`compose set ${set.name}`}
                    onSelect={run(() =>
                        router.visit(`${composeUrl}?destination=set:${set.id}`),
                    )}
                >
                    <ListChecks className="size-4" aria-hidden />
                    <span className="truncate">{set.name}</span>
                </CommandItem>
            ))}
        </CommandGroup>
    );
}

import { router } from '@inertiajs/react';

import { CommandGroup, CommandItem } from '@/components/ui/command';
import { Plug } from '@/components/ui/icons';
import {
    connect as accountConnect,
    index as accountsRoute,
} from '@/routes/accounts';

interface ConnectPlatformPageProps {
    run: (fn: () => void) => () => void;
}

const PLATFORMS = [
    ['x', 'X', true],
    ['linkedin', 'LinkedIn', true],
    ['bluesky', 'Bluesky', false],
] as const;

export function ConnectPlatformPage({ run }: ConnectPlatformPageProps) {
    return (
        <CommandGroup heading="Connect account">
            {PLATFORMS.map(([platform, label, isOAuth]) => (
                <CommandItem
                    key={platform}
                    value={`connect ${platform}`}
                    onSelect={run(() => {
                        if (isOAuth) {
                            window.location.href = accountConnect({
                                platform,
                            }).url;
                        } else {
                            router.visit(accountsRoute().url);
                        }
                    })}
                >
                    <Plug className="size-4" aria-hidden />
                    {label}
                </CommandItem>
            ))}
        </CommandGroup>
    );
}

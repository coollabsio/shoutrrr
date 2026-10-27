import { Link } from '@inertiajs/react';

import { index } from '@/routes/accounts';

type Account = { handle: string; status: string };

export function PipelineConnectionWarning({
    enabled,
    accounts,
}: {
    enabled: boolean;
    accounts: (Account | undefined)[];
}) {
    const unavailable = accounts.filter(
        (account) => !account || account.status !== 'active',
    );

    if (!enabled || unavailable.length === 0) {
        return null;
    }

    return (
        <p role="alert" className="text-sm text-destructive">
            Sync is blocked for{' '}
            {unavailable
                .map((account) => account?.handle ?? 'an unavailable account')
                .join(', ')}
            . Reconnect these accounts to resume affected syncs.{' '}
            <Link href={index()} className="font-medium underline">
                Check connected accounts
            </Link>
            .
        </p>
    );
}

import { Head, router, usePage } from '@inertiajs/react';
import { CircleAlert, Plug, X as XIcon } from 'lucide-react';
import { useState } from 'react';

import BlueskyOAuthController from '@/actions/App/Http/Controllers/ConnectedAccounts/BlueskyOAuthController';
import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import OAuthConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/OAuthConnectionController';
import { AccountCard } from '@/components/accounts/account-card';
import { ConnectButtons } from '@/components/accounts/connect-buttons';
import type { Account, Capability } from '@/components/accounts/types';
import Heading from '@/components/common/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { removeById } from '@/lib/optimistic';

export const ACCOUNT_GRID_CLASS = 'grid grid-cols-1 gap-4 lg:grid-cols-2';

/**
 * Resolve the OAuth redirect URL used to (re)connect an account. Bluesky has a
 * dedicated controller; every other platform shares the generic OAuth route.
 * A saved custom PDS is replayed as `pds_url` so reconnect targets the original
 * authorization server instead of falling back to the bsky.social default.
 */
export function reconnectOAuthUrl(account: Account): string {
    if (account.platform !== 'bluesky') {
        return OAuthConnectionController.redirect.url({
            platform: account.platform,
        });
    }

    return BlueskyOAuthController.redirect.url({
        query: account.pds_url ? { pds_url: account.pds_url } : {},
    });
}

type Props = {
    accounts: Account[];
    capabilities: Capability[];
    canManage: boolean;
};

export default function ConnectedAccounts({
    accounts,
    capabilities,
    canManage,
}: Props) {
    const disconnect = (account: Account) => {
        // The controller flashes a success message which FlashListener turns into
        // a toast — don't toast again here or it fires twice.
        router.delete(ConnectedAccountController.destroy.url(account.id), {
            preserveScroll: true,
            optimistic: (props) => ({
                accounts: removeById(
                    (props as { accounts?: Account[] }).accounts,
                    account.id,
                ),
            }),
        });
    };

    const reconnectOAuth = (account: Account) => {
        window.location.href = reconnectOAuthUrl(account);
    };

    const { flash } = usePage().props;
    const [dismissedError, setDismissedError] = useState<string | null>(null);
    // Connect/reconnect failures for every platform flash an `error`; surface it
    // as a persistent, dismissible banner (the toast alone is easy to miss).
    const connectError =
        flash?.error && flash.error !== dismissedError ? flash.error : null;

    const connectedCount = accounts.filter((a) => a.status === 'active').length;
    const attentionCount = accounts.length - connectedCount;

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 pt-6 pb-16 sm:px-6">
            <Head title="Accounts" />

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <Heading
                    title="Connected accounts"
                    description="Workspace-owned social accounts shared by every member."
                />
                {canManage && <ConnectButtons capabilities={capabilities} />}
            </div>

            {connectError && (
                <Alert variant="destructive" className="relative pr-10">
                    <CircleAlert />
                    <AlertTitle>Couldn't connect the account</AlertTitle>
                    <AlertDescription>{connectError}</AlertDescription>
                    <button
                        type="button"
                        onClick={() => setDismissedError(flash?.error ?? null)}
                        aria-label="Dismiss"
                        className="absolute top-3 right-3 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <XIcon className="size-4" />
                    </button>
                </Alert>
            )}

            {accounts.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <Plug />
                        </EmptyMedia>
                        <EmptyTitle>No accounts connected</EmptyTitle>
                        <EmptyDescription>
                            {canManage
                                ? 'Connect X, LinkedIn, or Bluesky to get started.'
                                : 'Ask an admin to connect one.'}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex items-center gap-4 text-[12.5px]">
                        <span className="flex items-center gap-1.5">
                            <span className="size-1.5 rounded-full bg-emerald-500" />
                            <span className="font-medium tabular-nums">
                                {connectedCount}
                            </span>
                            <span className="text-muted-foreground">
                                connected
                            </span>
                        </span>
                        {attentionCount > 0 && (
                            <span className="flex items-center gap-1.5">
                                <span className="size-1.5 rounded-full bg-destructive" />
                                <span className="font-medium text-destructive tabular-nums">
                                    {attentionCount}
                                </span>
                                <span className="text-muted-foreground">
                                    need{attentionCount === 1 ? 's' : ''}{' '}
                                    attention
                                </span>
                            </span>
                        )}
                    </div>

                    <div className={ACCOUNT_GRID_CLASS}>
                        {accounts.map((account) => (
                            <AccountCard
                                key={account.id}
                                account={account}
                                canManage={canManage}
                                onReconnectOAuth={reconnectOAuth}
                                onDisconnect={disconnect}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

ConnectedAccounts.layout = {
    breadcrumbs: [
        {
            title: 'Accounts',
            href: ConnectedAccountController.index().url,
        },
    ],
};

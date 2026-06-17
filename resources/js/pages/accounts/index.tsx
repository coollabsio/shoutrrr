import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    AtSign,
    BriefcaseBusiness,
    CircleAlert,
    Plug,
    RefreshCw,
    Trash2,
    X as XIcon,
} from 'lucide-react';
import { useState } from 'react';

import BlueskyConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/BlueskyConnectionController';
import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import OAuthConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/OAuthConnectionController';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { removeById } from '@/lib/optimistic';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

/** Per-platform brand accent for the glyph tile (encodes which network it is). */
const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-foreground/5', glyph: 'text-foreground' },
    linkedin: {
        tile: 'bg-blue-500/10',
        glyph: 'text-blue-600 dark:text-blue-400',
    },
    bluesky: { tile: 'bg-sky-500/10', glyph: 'text-sky-500' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

type Account = {
    id: string;
    platform: string;
    platform_label: string;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    status: 'active' | 'needs_attention';
    status_label: string;
    auth_method: string;
    connected_by: string | null;
    token_expires_at: string | null;
};

type Capability = {
    platform: string;
    label: string;
    supportsOAuth: boolean;
    supportsAppPassword: boolean;
    configured: boolean;
};

type Props = {
    accounts: Account[];
    capabilities: Capability[];
    canManage: boolean;
};

function platformIcon(platform: string) {
    switch (platform) {
        case 'x':
            return <XIcon className="size-4" />;
        case 'linkedin':
            return <BriefcaseBusiness className="size-4" />;
        default:
            return <AtSign className="size-4" />;
    }
}

function BlueskyConnectDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    className="w-full justify-center sm:w-auto"
                >
                    <AtSign className="size-4" />
                    Connect Bluesky
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Connect a Bluesky account</DialogTitle>
                    <DialogDescription>
                        Use an{' '}
                        <a
                            href="https://bsky.app/settings/app-passwords"
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            app password
                        </a>{' '}
                        instead of your main password. App passwords bypass 2FA,
                        and disconnecting here does not revoke them on Bluesky.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...BlueskyConnectionController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-4 py-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="identifier">
                                        Handle or email
                                    </Label>
                                    <Input
                                        id="identifier"
                                        name="identifier"
                                        placeholder="you.bsky.social"
                                        required
                                    />
                                    <InputError message={errors.identifier} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="app_password">
                                        App password
                                    </Label>
                                    <Input
                                        id="app_password"
                                        name="app_password"
                                        type="password"
                                        placeholder="xxxx-xxxx-xxxx-xxxx"
                                        required
                                    />
                                    <InputError message={errors.app_password} />
                                </div>
                                <Collapsible>
                                    <CollapsibleTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            Advanced: service URL
                                        </Button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="grid gap-2 pt-2">
                                        <Label htmlFor="pds_url">
                                            Service URL
                                        </Label>
                                        <Input
                                            id="pds_url"
                                            name="pds_url"
                                            placeholder="https://bsky.social"
                                        />
                                        <InputError message={errors.pds_url} />
                                    </CollapsibleContent>
                                </Collapsible>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Connecting...' : 'Connect'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ConnectButtons({ capabilities }: { capabilities: Capability[] }) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            {capabilities.map((capability) => {
                if (capability.supportsAppPassword) {
                    return <BlueskyConnectDialog key={capability.platform} />;
                }

                if (!capability.configured) {
                    return (
                        <Button
                            key={capability.platform}
                            variant="outline"
                            disabled
                            className="w-full justify-center sm:w-auto"
                        >
                            {platformIcon(capability.platform)}
                            Connect {capability.label}
                        </Button>
                    );
                }

                return (
                    <Button
                        key={capability.platform}
                        variant="outline"
                        asChild
                        className="w-full justify-center sm:w-auto"
                    >
                        <a
                            href={OAuthConnectionController.redirect.url({
                                platform: capability.platform,
                            })}
                        >
                            {platformIcon(capability.platform)}
                            Connect {capability.label}
                        </a>
                    </Button>
                );
            })}
        </div>
    );
}

function ReconnectBlueskyDialog({ account }: { account: Account }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    <RefreshCw className="size-4" />
                    Reconnect
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reconnect {account.handle}</DialogTitle>
                    <DialogDescription>
                        Re-enter the app password for this Bluesky account.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...ConnectedAccountController.reconnect.form(account.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-4 py-2">
                                <div className="grid gap-2">
                                    <Label htmlFor={`identifier-${account.id}`}>
                                        Handle or email
                                    </Label>
                                    <Input
                                        id={`identifier-${account.id}`}
                                        name="identifier"
                                        defaultValue={account.handle.replace(
                                            /^@/,
                                            '',
                                        )}
                                        required
                                    />
                                    <InputError message={errors.identifier} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={`password-${account.id}`}>
                                        App password
                                    </Label>
                                    <Input
                                        id={`password-${account.id}`}
                                        name="app_password"
                                        type="password"
                                        required
                                    />
                                    <InputError message={errors.app_password} />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Reconnecting...'
                                        : 'Reconnect'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function AccountCard({
    account,
    canManage,
    onReconnectOAuth,
    onDisconnect,
}: {
    account: Account;
    canManage: boolean;
    onReconnectOAuth: (account: Account) => void;
    onDisconnect: (account: Account) => void;
}) {
    const brand = PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;
    const needsAttention = account.status !== 'active';
    const name = account.display_name ?? account.handle;

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-xl border bg-card p-4 transition-colors',
                needsAttention ? 'border-destructive/40' : 'border-border',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="relative shrink-0">
                        <Avatar className="size-10">
                            <AvatarImage
                                src={account.avatar_url ?? undefined}
                                alt={account.handle}
                            />
                            <AvatarFallback className="text-[13px] font-medium">
                                {name
                                    .replace(/^@/, '')
                                    .slice(0, 1)
                                    .toUpperCase()}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            className={cn(
                                'absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full ring-2 ring-card',
                                brand.tile,
                                brand.glyph,
                            )}
                        >
                            <PlatformGlyph
                                platform={account.platform as PlatformName}
                                size={11}
                            />
                        </span>
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-[14px] font-medium">
                            {name}
                        </p>
                        <p className="truncate text-[12.5px] text-muted-foreground">
                            {account.handle}
                        </p>
                    </div>
                </div>

                <span className="flex shrink-0 items-center gap-1.5 text-[11.5px] font-medium">
                    <span
                        className={cn(
                            'size-1.5 rounded-full',
                            needsAttention
                                ? 'bg-destructive'
                                : 'bg-emerald-500',
                        )}
                    />
                    <span
                        className={
                            needsAttention
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        }
                    >
                        {needsAttention ? account.status_label : 'Connected'}
                    </span>
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[11.5px] text-muted-foreground">
                <span>{account.platform_label}</span>
                {account.connected_by && (
                    <>
                        <span aria-hidden>·</span>
                        <span className="truncate">
                            by {account.connected_by}
                        </span>
                    </>
                )}
            </div>

            {canManage && (
                <div className="mt-1 flex items-center gap-2 border-t border-border pt-3">
                    {account.auth_method === 'app_password' ? (
                        <ReconnectBlueskyDialog account={account} />
                    ) : (
                        <Button
                            variant={needsAttention ? 'default' : 'outline'}
                            size="sm"
                            className="h-8"
                            onClick={() => onReconnectOAuth(account)}
                        >
                            <RefreshCw className="size-4" />
                            Reconnect
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="ml-auto h-8 text-muted-foreground hover:text-destructive"
                        onClick={() => onDisconnect(account)}
                    >
                        <Trash2 className="size-4" />
                        Disconnect
                    </Button>
                </div>
            )}
        </div>
    );
}

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
        window.location.href = OAuthConnectionController.redirect.url({
            platform: account.platform,
        });
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

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
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

import { Form } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import InputError from '@/components/common/input-error';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

import type { Account } from './types';

/** Per-platform brand accent for the glyph tile (encodes which network it is). */
const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
    facebook: { tile: 'bg-[#1877F2]', glyph: 'text-white!' },
    instagram: { tile: 'bg-[#E4405F]', glyph: 'text-white!' },
    threads: { tile: 'bg-black', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

export const ACCOUNT_CARD_ACTIONS_CLASS =
    'mt-1 flex flex-wrap items-center gap-2 border-t border-border pt-4';

function ReconnectBlueskyDialog({ account }: { account: Account }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger
                render={
                    <Button variant="ghost" size="sm" className="shrink-0" />
                }
            >
                <RefreshCw className="size-4" />
                Reconnect
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reconnect {account.handle}</DialogTitle>
                    <DialogDescription>
                        Re-enter the app password for this Bluesky account. Use
                        an{' '}
                        <a
                            href="https://bsky.app/settings/app-passwords"
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            app password
                        </a>{' '}
                        instead of your main password.
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
                                    <InputGroup>
                                        <InputGroupAddon>@</InputGroupAddon>
                                        <InputGroupInput
                                            id={`identifier-${account.id}`}
                                            name="identifier"
                                            defaultValue={account.handle.replace(
                                                /^@/,
                                                '',
                                            )}
                                            aria-invalid={
                                                errors.identifier
                                                    ? true
                                                    : undefined
                                            }
                                            required
                                        />
                                    </InputGroup>
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

export function AccountCard({
    account,
    canManage,
    frozen,
    onReconnectOAuth,
    onDisconnect,
    onToggle,
}: {
    account: Account;
    canManage: boolean;
    frozen?: boolean;
    onReconnectOAuth: (account: Account) => void;
    onDisconnect: (account: Account) => void;
    onToggle: (account: Account, enabled: boolean) => void;
}) {
    const brand = PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;
    const needsAttention = account.status !== 'active';
    const disabled = account.disabled;
    const name = account.display_name ?? account.handle;

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-xl border bg-card p-5 transition-colors',
                needsAttention ? 'border-destructive/40' : 'border-border',
                disabled && 'opacity-60',
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
                                className={brand.glyph}
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

                <div className="flex shrink-0 items-center gap-2">
                    {frozen && (
                        <Badge variant="secondary" className="shrink-0">
                            Platform disabled
                        </Badge>
                    )}
                    {disabled && !frozen && (
                        <Badge variant="secondary" className="shrink-0">
                            Disabled
                        </Badge>
                    )}
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
                            {needsAttention
                                ? account.status_label
                                : 'Connected'}
                        </span>
                    </span>
                    {canManage && (
                        <Switch
                            checked={!disabled}
                            onCheckedChange={(checked) =>
                                onToggle(account, checked)
                            }
                            aria-label={
                                disabled
                                    ? `Enable ${account.handle}`
                                    : `Disable ${account.handle}`
                            }
                            className="ml-1"
                        />
                    )}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[11.5px] text-muted-foreground">
                <span>{account.platform_label}</span>
                {account.is_default && (
                    <>
                        <span aria-hidden>·</span>
                        <Badge
                            variant="success"
                            className="h-4 rounded-full px-1.5 text-[10.5px]"
                        >
                            Default
                        </Badge>
                    </>
                )}
                {account.x_premium && (
                    <>
                        <span aria-hidden>·</span>
                        <Badge
                            variant="info"
                            className="h-4 rounded-full px-1.5 text-[10.5px]"
                        >
                            Premium
                        </Badge>
                    </>
                )}
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
                <div className={ACCOUNT_CARD_ACTIONS_CLASS}>
                    {!frozen &&
                        (account.auth_method === 'app_password' ? (
                            <ReconnectBlueskyDialog account={account} />
                        ) : (
                            <Button
                                variant={needsAttention ? 'default' : 'outline'}
                                size="sm"
                                className="h-8 shrink-0"
                                onClick={() => onReconnectOAuth(account)}
                            >
                                <RefreshCw className="size-4" />
                                Reconnect
                            </Button>
                        ))}
                    {!account.is_default && (
                        <Form
                            {...ConnectedAccountController.makeDefault.form(
                                account.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="shrink-0"
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 text-muted-foreground"
                                    disabled={processing}
                                >
                                    Make default
                                </Button>
                            )}
                        </Form>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 shrink-0 text-muted-foreground hover:text-destructive sm:ml-auto"
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

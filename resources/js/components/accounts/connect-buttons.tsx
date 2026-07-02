import { Form } from '@inertiajs/react';
import { AtSign, ChevronDown, Loader2 } from 'lucide-react';
import { useState } from 'react';

import BlueskyConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/BlueskyConnectionController';
import BlueskyOAuthController from '@/actions/App/Http/Controllers/ConnectedAccounts/BlueskyOAuthController';
import OAuthConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/OAuthConnectionController';
import InputError from '@/components/common/input-error';
import { PlatformGlyph } from '@/components/common/platform-glyph';
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
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { useBlueskyHandleResolver } from '@/hooks/use-bluesky-handle-resolver';
import type { PlatformName } from '@/types/compose';

import type { Capability } from './types';

export const COLLAPSIBLE_TRIGGER_ICON_CLASS =
    '[&[data-state=open]_svg]:rotate-180';

const SUPPORTED_PLATFORM_ICONS = ['x', 'bluesky', 'linkedin'];

export function isSupportedPlatformIcon(
    platform: string,
): platform is PlatformName {
    return SUPPORTED_PLATFORM_ICONS.includes(platform);
}

function platformIcon(platform: string) {
    if (!isSupportedPlatformIcon(platform)) {
        return <AtSign className="size-4" />;
    }

    return <PlatformGlyph platform={platform} size={16} className="size-4" />;
}

function BlueskyConnectDialog() {
    const [open, setOpen] = useState(false);
    const [appPasswordOpen, setAppPasswordOpen] = useState(false);
    const [oauthLoading, setOauthLoading] = useState(false);
    const [handle, setHandle] = useState('');
    const resolver = useBlueskyHandleResolver();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    className="w-full justify-center sm:w-auto"
                >
                    {platformIcon('bluesky')}
                    Connect Bluesky
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Connect a Bluesky account</DialogTitle>
                    <DialogDescription>
                        Enter your Bluesky handle to sign in.
                    </DialogDescription>
                </DialogHeader>
                <form
                    {...BlueskyOAuthController.redirect.form()}
                    className="space-y-4 py-2"
                    onSubmit={() => setOauthLoading(true)}
                >
                    <div className="relative grid gap-2">
                        <Label htmlFor="oauth_identifier">Handle</Label>
                        <InputGroup>
                            <InputGroupAddon>
                                {resolver.avatar ? (
                                    <img
                                        src={resolver.avatar}
                                        alt=""
                                        className="size-4 rounded-full object-cover"
                                    />
                                ) : (
                                    '@'
                                )}
                            </InputGroupAddon>
                            <InputGroupInput
                                id="oauth_identifier"
                                name="identifier"
                                placeholder="you.bsky.social"
                                required
                                value={handle}
                                autoComplete="off"
                                role="combobox"
                                aria-expanded={
                                    resolver.suggestionsOpen &&
                                    resolver.suggestions.length > 0
                                }
                                aria-controls="bluesky-handle-listbox"
                                aria-activedescendant={
                                    resolver.selectedIdx >= 0
                                        ? `bluesky-handle-option-${resolver.selectedIdx}`
                                        : undefined
                                }
                                onChange={(e) => {
                                    setHandle(e.target.value);
                                    resolver.onInput(e.target.value);
                                }}
                                onKeyDown={(e) => {
                                    if (
                                        e.key === 'Enter' &&
                                        resolver.selectedIdx >= 0
                                    ) {
                                        e.preventDefault();
                                        const s =
                                            resolver.suggestions[
                                                resolver.selectedIdx
                                            ];
                                        const h = resolver.selectSuggestion(
                                            s.handle,
                                            s.avatar,
                                        );
                                        setHandle(h);
                                    } else {
                                        resolver.onKeydown(e);
                                    }
                                }}
                                onBlur={() =>
                                    setTimeout(
                                        () =>
                                            resolver.setSuggestionsOpen(false),
                                        150,
                                    )
                                }
                                onFocus={() => {
                                    if (resolver.suggestions.length) {
                                        resolver.setSuggestionsOpen(true);
                                    }
                                }}
                            />
                        </InputGroup>
                        {resolver.suggestionsOpen &&
                            resolver.suggestions.length > 0 && (
                                <div
                                    id="bluesky-handle-listbox"
                                    role="listbox"
                                    className="absolute top-full right-0 left-0 z-50 mt-1 rounded-xl border bg-popover p-1 text-popover-foreground shadow-md"
                                >
                                    {resolver.suggestions.map((s, i) => (
                                        <button
                                            key={s.did}
                                            type="button"
                                            role="option"
                                            id={`bluesky-handle-option-${i}`}
                                            aria-selected={
                                                i === resolver.selectedIdx
                                            }
                                            className={`flex w-full items-center gap-2 rounded-xl px-2 py-1.5 text-sm outline-hidden select-none hover:bg-muted ${i === resolver.selectedIdx ? 'bg-muted' : ''}`}
                                            onMouseDown={(e) => {
                                                e.preventDefault();
                                                const h =
                                                    resolver.selectSuggestion(
                                                        s.handle,
                                                        s.avatar,
                                                    );
                                                setHandle(h);
                                            }}
                                        >
                                            {s.avatar ? (
                                                <img
                                                    src={s.avatar}
                                                    alt=""
                                                    className="size-6 shrink-0 rounded-full object-cover"
                                                />
                                            ) : (
                                                <div className="size-6 shrink-0 rounded-full bg-muted-foreground/20" />
                                            )}
                                            <span className="truncate font-medium">
                                                {s.displayName || s.handle}
                                            </span>
                                            {s.displayName && (
                                                <span className="truncate text-muted-foreground">
                                                    @{s.handle}
                                                </span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            )}
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={oauthLoading}
                    >
                        {oauthLoading && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        Continue with Bluesky
                    </Button>
                </form>
                <Collapsible
                    open={appPasswordOpen}
                    onOpenChange={setAppPasswordOpen}
                >
                    <div className="flex items-center gap-3 py-1 text-[11px] tracking-wide text-muted-foreground uppercase">
                        <span className="h-px flex-1 bg-border" />
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className={COLLAPSIBLE_TRIGGER_ICON_CLASS}
                            >
                                Use app password instead
                                <ChevronDown
                                    aria-hidden="true"
                                    data-icon="inline-end"
                                    className="size-4 text-muted-foreground transition-transform"
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <span className="h-px flex-1 bg-border" />
                    </div>
                    <CollapsibleContent>
                        <p className="pb-2 text-sm text-muted-foreground">
                            <strong>Not recommended.</strong> App passwords
                            bypass 2FA, and disconnecting here does not revoke
                            them on Bluesky. You can manage them on{' '}
                            <a
                                href="https://bsky.app/settings/app-passwords"
                                target="_blank"
                                rel="noreferrer"
                                className="underline"
                            >
                                Bluesky
                            </a>
                            .
                        </p>
                        <Form
                            {...BlueskyConnectionController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            onSuccess={() => setOpen(false)}
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="identifier">
                                                Handle or email
                                            </Label>
                                            <InputGroup>
                                                <InputGroupAddon>
                                                    @
                                                </InputGroupAddon>
                                                <InputGroupInput
                                                    id="identifier"
                                                    name="identifier"
                                                    placeholder="you.bsky.social"
                                                    aria-invalid={
                                                        errors.identifier
                                                            ? true
                                                            : undefined
                                                    }
                                                    required
                                                />
                                            </InputGroup>
                                            <InputError
                                                message={errors.identifier}
                                            />
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
                                            <InputError
                                                message={errors.app_password}
                                            />
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setOpen(false)}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing
                                                ? 'Connecting...'
                                                : 'Connect'}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </CollapsibleContent>
                </Collapsible>
            </DialogContent>
        </Dialog>
    );
}

export function ConnectButtons({
    capabilities,
}: {
    capabilities: Capability[];
}) {
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

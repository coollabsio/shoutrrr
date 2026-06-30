import { Form } from '@inertiajs/react';
import { AtSign, ChevronDown } from 'lucide-react';
import { useState } from 'react';

import BlueskyConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/BlueskyConnectionController';
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
import type { PlatformName } from '@/types/compose';

import type { Capability } from './types';

export const ADVANCED_SERVICE_URL_TRIGGER_CLASS =
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
                                    <InputGroup>
                                        <InputGroupAddon>@</InputGroupAddon>
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
                                            className={
                                                ADVANCED_SERVICE_URL_TRIGGER_CLASS
                                            }
                                        >
                                            Advanced: service URL
                                            <ChevronDown
                                                aria-hidden="true"
                                                data-icon="inline-end"
                                                className="size-4 text-muted-foreground transition-transform"
                                            />
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

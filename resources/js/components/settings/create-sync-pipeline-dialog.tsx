import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import { AccountAvatar } from '@/components/common/account-avatar';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Plus } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

export type SyncAccount = {
    id: string;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    status: string;
    supports_native: boolean;
};

type Props = {
    accounts: SyncAccount[];
    disabled: boolean;
    trackedAccountIds: string[];
    canTrack: boolean;
    maxTracked: number;
};

const PLATFORM_LABEL: Record<string, string> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
    facebook: 'Facebook',
    instagram: 'Instagram',
    threads: 'Threads',
    discord: 'Discord',
};

function platformLabel(platform: string): string {
    return PLATFORM_LABEL[platform] ?? platform;
}

function accountName(account: SyncAccount): string {
    return account.display_name ?? account.handle;
}

/** Avatar + name, with the @handle in muted next to it when it differs. */
function AccountRow({ account }: { account: SyncAccount }) {
    return (
        <>
            <AccountAvatar
                platform={account.platform}
                handle={account.handle}
                avatarUrl={account.avatar_url}
                size="md"
                ringClassName="ring-popover"
            />
            <span className="min-w-0 truncate">
                {accountName(account)}
                {account.handle !== accountName(account) && (
                    <span className="ml-1.5 text-muted-foreground">
                        {account.handle}
                    </span>
                )}
            </span>
        </>
    );
}

export default function CreateSyncPipelineDialog({
    accounts,
    disabled,
    trackedAccountIds,
    canTrack,
    maxTracked,
}: Props) {
    const [open, setOpen] = useState(false);
    const [source, setSource] = useState<string>('');
    const [destinations, setDestinations] = useState<string[]>([]);
    const [trackSource, setTrackSource] = useState(true);

    function selectSource(id: string) {
        setSource(id);
        setDestinations((current) => current.filter((d) => d !== id));
        setTrackSource(true);
    }

    function toggleDestination(id: string) {
        setDestinations((current) =>
            current.includes(id)
                ? current.filter((d) => d !== id)
                : [...current, id],
        );
    }

    function reset() {
        setSource('');
        setDestinations([]);
        setTrackSource(true);
    }

    const sourceAccount = accounts.find((account) => account.id === source);
    const sourceTracked =
        sourceAccount !== undefined && trackedAccountIds.includes(source);
    const canOfferTracking =
        sourceAccount?.supports_native === true && !sourceTracked && canTrack;
    // Only submit track_source=1 when the offer is actually shown and checked.
    const willTrackSource = canOfferTracking && trackSource;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button disabled={disabled} />}>
                <Plus />
                New pipeline
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New sync pipeline</DialogTitle>
                </DialogHeader>
                <Form
                    {...SyncPipelinesController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        toast.success('Sync pipeline created');
                        setOpen(false);
                        reset();
                    }}
                >
                    {({
                        errors,
                        processing,
                    }: {
                        errors: Record<string, string>;
                        processing: boolean;
                    }) => (
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    placeholder="X → LinkedIn"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Source account</Label>
                                <RadioGroup
                                    value={source}
                                    onValueChange={(value) =>
                                        selectSource(value as string)
                                    }
                                >
                                    {accounts.map((account) => (
                                        <div
                                            key={account.id}
                                            className="flex items-center gap-3 text-sm"
                                        >
                                            <RadioGroupItem
                                                id={`source-${account.id}`}
                                                value={account.id}
                                            />
                                            <Label
                                                htmlFor={`source-${account.id}`}
                                                className="flex min-w-0 flex-1 cursor-pointer items-center gap-3 font-normal"
                                            >
                                                <AccountRow account={account} />
                                            </Label>
                                        </div>
                                    ))}
                                </RadioGroup>
                                <input
                                    type="hidden"
                                    name="source_connected_account_id"
                                    value={source}
                                />
                                <InputError
                                    message={errors.source_connected_account_id}
                                />
                            </div>

                            {source !== '' && (
                                <div className="grid gap-2">
                                    <Label>Destinations</Label>
                                    {accounts
                                        .filter(
                                            (account) => account.id !== source,
                                        )
                                        .map((account) => (
                                            <div
                                                key={account.id}
                                                className="flex items-center gap-3 text-sm"
                                            >
                                                <Checkbox
                                                    id={`dest-${account.id}`}
                                                    checked={destinations.includes(
                                                        account.id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggleDestination(
                                                            account.id,
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`dest-${account.id}`}
                                                    className="flex min-w-0 flex-1 cursor-pointer items-center gap-3 font-normal"
                                                >
                                                    <AccountRow
                                                        account={account}
                                                    />
                                                </Label>
                                            </div>
                                        ))}
                                    {destinations.map((id) => (
                                        <input
                                            key={id}
                                            type="hidden"
                                            name="destination_connected_account_ids[]"
                                            value={id}
                                        />
                                    ))}
                                    <InputError
                                        message={
                                            errors.destination_connected_account_ids
                                        }
                                    />
                                </div>
                            )}

                            {sourceAccount && (
                                <div className="rounded-lg border bg-muted/30 p-3">
                                    {canOfferTracking ? (
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="track_source"
                                                checked={trackSource}
                                                onCheckedChange={(checked) =>
                                                    setTrackSource(
                                                        checked === true,
                                                    )
                                                }
                                                className="mt-0.5"
                                            />
                                            <Label
                                                htmlFor="track_source"
                                                className="cursor-pointer font-normal"
                                            >
                                                <span className="block text-sm">
                                                    Also track native posts from
                                                    this source
                                                </span>
                                                <span className="block text-sm text-muted-foreground">
                                                    Sync posts you make directly
                                                    on{' '}
                                                    {platformLabel(
                                                        sourceAccount.platform,
                                                    )}
                                                    , not just ones published
                                                    through Shoutrrr.
                                                </span>
                                            </Label>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {!sourceAccount.supports_native
                                                ? `${platformLabel(sourceAccount.platform)} posts only sync when published through Shoutrrr — native posts can't be tracked.`
                                                : sourceTracked
                                                  ? 'Native posts from this account are already tracked.'
                                                  : `Native tracking is full (${maxTracked} accounts). This pipeline will only sync posts published through Shoutrrr until you untrack an account.`}
                                        </p>
                                    )}
                                    <input
                                        type="hidden"
                                        name="track_source"
                                        value={willTrackSource ? '1' : '0'}
                                    />
                                </div>
                            )}

                            <Button type="submit" disabled={processing}>
                                {processing ? 'Creating…' : 'Create pipeline'}
                            </Button>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

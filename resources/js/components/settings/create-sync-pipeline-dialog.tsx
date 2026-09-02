import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
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

export type SyncAccount = {
    id: string;
    platform: string;
    handle: string;
    display_name: string | null;
    status: string;
};

type Props = {
    accounts: SyncAccount[];
    disabled: boolean;
};

function accountLabel(account: SyncAccount): string {
    return `${account.display_name ?? account.handle} (${account.platform})`;
}

export default function CreateSyncPipelineDialog({
    accounts,
    disabled,
}: Props) {
    const [open, setOpen] = useState(false);
    const [source, setSource] = useState<string>('');
    const [destinations, setDestinations] = useState<string[]>([]);

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
    }

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
                                {accounts.map((account) => (
                                    <label
                                        key={account.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="radio"
                                            name="source_connected_account_id"
                                            value={account.id}
                                            checked={source === account.id}
                                            onChange={() =>
                                                setSource(account.id)
                                            }
                                        />
                                        {accountLabel(account)}
                                    </label>
                                ))}
                                <InputError
                                    message={errors.source_connected_account_id}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label>Destinations</Label>
                                {accounts
                                    .filter((account) => account.id !== source)
                                    .map((account) => (
                                        <label
                                            key={account.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={destinations.includes(
                                                    account.id,
                                                )}
                                                onChange={() =>
                                                    toggleDestination(
                                                        account.id,
                                                    )
                                                }
                                            />
                                            {accountLabel(account)}
                                        </label>
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

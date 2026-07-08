import { Form, Head, router, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import { useConfirm } from '@/components/common/confirm-dialog';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dayjs } from '@/lib/datetime/dayjs';

type ApiKey = {
    id: string;
    name: string;
    last_four: string | null;
    scope: 'read' | 'write';
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
};

type Props = {
    apiKeys: ApiKey[];
};

function lastUsedLabel(iso: string | null): string {
    return iso ? `Last used ${dayjs(iso).fromNow()}` : 'Never used';
}

function createdLabel(iso: string): string {
    return `Created ${dayjs(iso).format('MMM D, YYYY')}`;
}

function expiresLabel(iso: string | null): { text: string; expired: boolean } {
    if (!iso) {
        return { text: 'Never expires', expired: false };
    }
    const date = dayjs(iso);
    const expired = date.isBefore(dayjs());
    return {
        text: `${expired ? 'Expired' : 'Expires'} ${date.format('MMM D, YYYY')}`,
        expired,
    };
}

export default function ApiKeys({ apiKeys }: Props) {
    const { flash } = usePage().props;
    const confirm = useConfirm();
    const [copied, setCopied] = useState(false);

    function copyToken(token: string) {
        void navigator.clipboard?.writeText(token);
        setCopied(true);
        toast.success('Copied to clipboard');
        setTimeout(() => setCopied(false), 2000);
    }

    async function revokeKey(key: ApiKey) {
        const confirmed = await confirm({
            title: `Revoke “${key.name}”?`,
            description:
                'Anything using this key loses access immediately. This cannot be undone.',
            actionLabel: 'Revoke key',
            destructive: true,
        });

        if (confirmed) {
            router.delete(ApiKeysController.destroy(key.id).url, {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title="API keys" />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="API keys"
                    description="Use the Shoutrrr API from scripts, cron jobs, and integrations. Each key acts on this workspace only."
                />

                {flash?.plainTextApiKey && (
                    <div className="space-y-3 rounded-lg border border-primary/30 bg-primary/5 p-4">
                        <div className="flex items-center gap-2">
                            <KeyRound className="size-4 text-primary" />
                            <p className="text-sm font-medium">Key created</p>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            This is the only time you&apos;ll see the full key.
                            Copy it now and store it somewhere safe.
                        </p>
                        <div className="flex items-center gap-2">
                            <Input
                                readOnly
                                value={flash.plainTextApiKey}
                                aria-label="New API key"
                                onFocus={(event) => event.target.select()}
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                className="shrink-0"
                                onClick={() =>
                                    copyToken(flash.plainTextApiKey as string)
                                }
                            >
                                {copied ? (
                                    <Check className="size-4" />
                                ) : (
                                    <Copy className="size-4" />
                                )}
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                )}

                <section className="space-y-4 rounded-lg border p-5">
                    <div>
                        <h3 className="text-sm font-medium">Create a key</h3>
                        <p className="text-sm text-muted-foreground">
                            Read keys can fetch data; read &amp; write keys can
                            also create and change it.
                        </p>
                    </div>

                    <Form
                        {...ApiKeysController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            placeholder="CI deploy bot"
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="scope">Access</Label>
                                        <Select
                                            name="scope"
                                            defaultValue="read"
                                        >
                                            <SelectTrigger
                                                id="scope"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="read">
                                                    Read
                                                </SelectItem>
                                                <SelectItem value="write">
                                                    Read &amp; write
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.scope} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="expires_at">
                                            Expires
                                        </Label>
                                        <Input
                                            id="expires_at"
                                            name="expires_at"
                                            type="date"
                                        />
                                        <InputError
                                            message={errors.expires_at}
                                        />
                                    </div>
                                </div>

                                <Button type="submit" disabled={processing}>
                                    <Plus className="size-4" />
                                    {processing ? 'Creating…' : 'Create key'}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                {apiKeys.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <KeyRound className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            No API keys yet
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Create a key above to start calling the API.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {apiKeys.map((key) => {
                            const expires = expiresLabel(key.expires_at);

                            return (
                                <li
                                    key={key.id}
                                    className="flex items-center gap-4 p-4"
                                >
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <KeyRound className="size-4" />
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span className="truncate font-medium">
                                                {key.name}
                                            </span>
                                            {key.last_four && (
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    ••••{key.last_four}
                                                </span>
                                            )}
                                            {key.scope === 'write' ? (
                                                <Badge>Read &amp; write</Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    Read
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="mt-1 truncate text-xs text-muted-foreground">
                                            {lastUsedLabel(key.last_used_at)}
                                            <span aria-hidden> · </span>
                                            {createdLabel(key.created_at)}
                                            <span aria-hidden> · </span>
                                            <span
                                                className={
                                                    expires.expired
                                                        ? 'text-destructive'
                                                        : undefined
                                                }
                                            >
                                                {expires.text}
                                            </span>
                                        </p>
                                    </div>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="shrink-0 text-muted-foreground hover:text-destructive"
                                        onClick={() => revokeKey(key)}
                                    >
                                        Revoke
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </>
    );
}

ApiKeys.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: WorkspaceSettingsController.showOverview().url,
        },
        {
            title: 'API keys',
            href: ApiKeysController.index().url,
        },
    ],
};

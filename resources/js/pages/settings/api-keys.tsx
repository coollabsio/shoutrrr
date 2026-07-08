import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { apiKeys as apiKeysIndex } from '@/routes/settings';

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

function formatDate(value: string | null): string | null {
    return value ? new Date(value).toLocaleDateString() : null;
}

export default function ApiKeys({ apiKeys }: Props) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(false);

    function copyToken(token: string) {
        void navigator.clipboard?.writeText(token);
        setCopied(true);
        toast.success('Copied to clipboard');
    }

    return (
        <>
            <Head title="API keys" />

            <h1 className="sr-only">API keys</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="API keys"
                    description="Create and manage API keys for programmatic access to this workspace"
                />

                {flash?.plainTextApiKey && (
                    <div className="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                        <p className="text-sm font-medium">Your new API key</p>
                        <p className="text-sm text-muted-foreground">
                            Copy it now — you won&apos;t be able to see it
                            again.
                        </p>
                        <div className="flex items-center gap-2">
                            <Input
                                readOnly
                                value={flash.plainTextApiKey}
                                aria-label="New API key"
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    copyToken(flash.plainTextApiKey as string)
                                }
                            >
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                )}

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
                                        placeholder="CI bot"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="scope">Scope</Label>
                                    <Select name="scope" defaultValue="read">
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
                                                Read-write
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.scope} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="expires_at">
                                        Expires (optional)
                                    </Label>
                                    <Input
                                        id="expires_at"
                                        name="expires_at"
                                        type="date"
                                    />
                                    <InputError message={errors.expires_at} />
                                </div>
                            </div>

                            <Button type="submit" disabled={processing}>
                                {processing ? 'Creating…' : 'Create key'}
                            </Button>
                        </>
                    )}
                </Form>

                {apiKeys.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No API keys yet.
                    </p>
                ) : (
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Scope</TableHead>
                                    <TableHead>Last used</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead className="w-12 text-right">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {apiKeys.map((key) => (
                                    <TableRow key={key.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                <span>{key.name}</span>
                                                {key.last_four && (
                                                    <span className="font-mono text-xs text-muted-foreground">
                                                        ••••{key.last_four}
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    key.scope === 'write'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {key.scope === 'write'
                                                    ? 'Read-write'
                                                    : 'Read'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(key.last_used_at) ??
                                                'Never'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(key.created_at)}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(key.expires_at) ??
                                                'Never'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Form
                                                {...ApiKeysController.destroy.form(
                                                    key.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onBefore={() =>
                                                    confirm(
                                                        `Revoke "${key.name}"? This cannot be undone.`,
                                                    )
                                                }
                                            >
                                                {({ processing: revoking }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={revoking}
                                                    >
                                                        Revoke
                                                    </Button>
                                                )}
                                            </Form>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}

ApiKeys.layout = {
    breadcrumbs: [
        {
            title: 'API keys',
            href: apiKeysIndex(),
        },
    ],
};

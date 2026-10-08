import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import LinkedInPageConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/LinkedInPageConnectionController';
import Heading from '@/components/common/heading';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

type LinkedInOrganization = {
    id: string;
    urn: string;
    name: string;
    vanityName: string;
};

type Selection = { type: 'organization'; id: string };

type Props = { organizations: LinkedInOrganization[] };

export default function ConnectLinkedIn({ organizations }: Props) {
    const [checkedOrgs, setCheckedOrgs] = useState<Record<string, boolean>>({});
    const [processing, setProcessing] = useState(false);

    const selection: Selection[] = organizations
        .filter((org) => checkedOrgs[org.id])
        .map((org) => ({ type: 'organization' as const, id: org.id }));

    const submit = () => {
        router.post(
            LinkedInPageConnectionController.store.url(),
            { selected: selection },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-6 pb-16 sm:px-6">
            <Head title="Connect LinkedIn" />
            <Heading
                title="Choose which Pages to connect"
                description="Connect any LinkedIn Pages you administer to this workspace."
            />
            <div className="flex flex-col gap-4">
                {organizations.map((org) => (
                    <label
                        key={org.id}
                        className="flex items-center gap-3 rounded-xl border p-4"
                    >
                        <Checkbox
                            checked={!!checkedOrgs[org.id]}
                            onCheckedChange={(c) =>
                                setCheckedOrgs((prev) => ({
                                    ...prev,
                                    [org.id]: c === true,
                                }))
                            }
                        />
                        <PlatformGlyph
                            platform="linkedin"
                            size={16}
                            className="size-4"
                        />
                        <span className="font-medium">{org.name}</span>
                        <span className="text-sm text-muted-foreground">
                            Page
                        </span>
                    </label>
                ))}

                <Button
                    type="button"
                    onClick={submit}
                    disabled={selection.length === 0 || processing}
                    className="w-full sm:w-auto"
                >
                    Connect selected
                </Button>
            </div>
        </div>
    );
}

ConnectLinkedIn.layout = {
    breadcrumbs: [
        { title: 'Accounts', href: ConnectedAccountController.index().url },
    ],
};

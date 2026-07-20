import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import LinkedInPageConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/LinkedInPageConnectionController';
import Heading from '@/components/common/heading';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

type LinkedInPerson = {
    remoteAccountId: string;
    handle: string;
    displayName: string | null;
    avatarUrl: string | null;
};

type LinkedInOrganization = {
    id: string;
    urn: string;
    name: string;
    vanityName: string;
};

type Selection = { type: 'person' } | { type: 'organization'; id: string };

type Props = { person: LinkedInPerson; organizations: LinkedInOrganization[] };

export default function ConnectLinkedIn({ person, organizations }: Props) {
    const [personChecked, setPersonChecked] = useState(true);
    const [checkedOrgs, setCheckedOrgs] = useState<Record<string, boolean>>({});

    const selection: Selection[] = [
        ...(personChecked ? [{ type: 'person' as const }] : []),
        ...organizations
            .filter((org) => checkedOrgs[org.id])
            .map((org) => ({ type: 'organization' as const, id: org.id })),
    ];

    const submit = () => {
        router.post(LinkedInPageConnectionController.store.url(), {
            selected: selection,
        });
    };

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-6 pb-16 sm:px-6">
            <Head title="Connect LinkedIn" />
            <Heading
                title="Choose what to connect"
                description="Connect your personal LinkedIn profile and any Pages you administer to this workspace."
            />
            <div className="flex flex-col gap-4">
                <label className="flex items-center gap-3 rounded-xl border p-4">
                    <Checkbox
                        checked={personChecked}
                        onCheckedChange={(c) => setPersonChecked(c === true)}
                    />
                    <PlatformGlyph
                        platform="linkedin"
                        size={16}
                        className="size-4"
                    />
                    <span className="font-medium">
                        {person.displayName ?? person.handle}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        Personal profile
                    </span>
                </label>

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
                    disabled={selection.length === 0}
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

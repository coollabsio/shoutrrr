import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import GoogleBusinessProfileConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/GoogleBusinessProfileConnectionController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

export type GoogleBusinessProfileLocation = {
    key: string;
    title: string;
    storeCode: string | null;
    addressLabel: string | null;
    mapsUri: string | null;
    canOperateLocalPost: boolean;
    readinessIssues: { code: string; message: string }[];
};

export function buildGoogleBusinessProfileSelection(
    selected: Record<string, boolean>,
    locations: GoogleBusinessProfileLocation[],
): string[] {
    return locations
        .filter((location) => location.canOperateLocalPost && selected[location.key])
        .map((location) => location.key);
}

export function canConnectGoogleBusinessProfileLocations(
    selection: string[],
    consent: boolean,
): boolean {
    return consent && selection.length > 0;
}

export default function ConnectGoogleBusinessProfile({
    locations,
}: {
    locations: GoogleBusinessProfileLocation[];
    readinessIssues: unknown[];
}) {
    const [selected, setSelected] = useState<Record<string, boolean>>({});
    const [consent, setConsent] = useState(false);
    const selection = buildGoogleBusinessProfileSelection(selected, locations);
    const canConnect = canConnectGoogleBusinessProfileLocations(selection, consent);

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-6 pb-16 sm:px-6">
            <Head title="Connect Google Business Profile" />
            <Heading title="Choose locations to connect" description="Only locations that Google allows to manage Local Posts can be connected." />
            <div className="flex flex-col gap-3">
                {locations.map((location) => (
                    <label key={location.key} className="flex gap-3 rounded-xl border p-4">
                        <Checkbox disabled={!location.canOperateLocalPost} checked={!!selected[location.key]} onCheckedChange={() => setSelected((current) => ({ ...current, [location.key]: !current[location.key] }))} />
                        <span className="flex flex-col gap-1"><span className="font-medium">{location.title}</span><span className="text-sm text-muted-foreground">{location.addressLabel ?? location.key}</span>{!location.canOperateLocalPost && <span className="text-sm text-destructive">{location.readinessIssues[0]?.message ?? 'Google does not allow Local Posts for this location.'}</span>}</span>
                    </label>
                ))}
            </div>
            <label className="flex items-start gap-3 rounded-xl border p-4"><Checkbox checked={consent} onCheckedChange={(checked) => setConsent(checked === true)} /><span className="text-sm">I confirm that I am authorized to connect these locations and allow Shoutrrr to manage Local Posts for them.</span></label>
            <Button disabled={!canConnect} onClick={() => router.post(GoogleBusinessProfileConnectionController.store.url(), { selected: selection, consent: true })}>Connect selected locations</Button>
        </div>
    );
}

ConnectGoogleBusinessProfile.layout = { breadcrumbs: [{ title: 'Accounts', href: ConnectedAccountController.index().url }] };

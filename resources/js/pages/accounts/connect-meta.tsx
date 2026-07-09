import { Head, router } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import { useState } from 'react';

import ConnectedAccountController from '@/actions/App/Http/Controllers/ConnectedAccounts/ConnectedAccountController';
import MetaConnectionController from '@/actions/App/Http/Controllers/ConnectedAccounts/MetaConnectionController';
import Heading from '@/components/common/heading';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';

export type MetaAsset = {
    key: string;
    pageId: string;
    pageName: string;
    igUserId: string | null;
    igUsername: string | null;
    igAvatarUrl: string | null;
    platforms: string[];
};

export type MetaSelection = { assetKey: string; platform: string };

type SelectionState = Record<string, Record<string, boolean>>;

/**
 * Flattens the per-asset/per-platform checkbox state into the
 * `{assetKey, platform}[]` shape `accounts.meta.store` expects. Unchecked and
 * never-toggled pairs are dropped entirely.
 */
export function buildMetaSelection(selected: SelectionState): MetaSelection[] {
    return Object.entries(selected).flatMap(([assetKey, platforms]) =>
        Object.entries(platforms)
            .filter(([, checked]) => checked)
            .map(([platform]) => ({ assetKey, platform })),
    );
}

type Props = {
    assets: MetaAsset[];
};

export default function ConnectMeta({ assets }: Props) {
    const [selected, setSelected] = useState<SelectionState>({});

    const toggle = (assetKey: string, platform: string) => {
        setSelected((prev) => ({
            ...prev,
            [assetKey]: {
                ...prev[assetKey],
                [platform]: !prev[assetKey]?.[platform],
            },
        }));
    };

    const selection = buildMetaSelection(selected);

    const submit = () => {
        router.post(MetaConnectionController.store.url(), {
            selected: selection,
        });
    };

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-6 pb-16 sm:px-6">
            <Head title="Connect Facebook" />

            <Heading
                title="Choose Pages to connect"
                description="Select which Facebook Pages — and their linked Instagram accounts — to connect to this workspace."
            />

            {assets.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <Plug />
                        </EmptyMedia>
                        <EmptyTitle>No Pages found</EmptyTitle>
                        <EmptyDescription>
                            We couldn't find any Facebook Pages on your account.
                            Create a Page on Facebook, then reconnect.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="flex flex-col gap-4">
                    {assets.map((asset) => (
                        <div
                            key={asset.key}
                            className="flex flex-col gap-3 rounded-xl border p-4"
                        >
                            <label className="flex items-center gap-3">
                                <Checkbox
                                    checked={!!selected[asset.key]?.facebook}
                                    onCheckedChange={() =>
                                        toggle(asset.key, 'facebook')
                                    }
                                />
                                <PlatformGlyph
                                    platform="facebook"
                                    size={16}
                                    className="size-4"
                                />
                                <span className="font-medium">
                                    {asset.pageName}
                                </span>
                            </label>

                            {asset.platforms.includes('instagram') && (
                                <label className="ml-7 flex items-center gap-3">
                                    <Checkbox
                                        checked={
                                            !!selected[asset.key]?.instagram
                                        }
                                        onCheckedChange={() =>
                                            toggle(asset.key, 'instagram')
                                        }
                                    />
                                    {asset.igAvatarUrl ? (
                                        <img
                                            src={asset.igAvatarUrl}
                                            alt=""
                                            className="size-5 rounded-full object-cover"
                                        />
                                    ) : (
                                        <PlatformGlyph
                                            platform="instagram"
                                            size={16}
                                            className="size-4"
                                        />
                                    )}
                                    <span className="text-sm text-muted-foreground">
                                        @{asset.igUsername}
                                    </span>
                                </label>
                            )}
                        </div>
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
            )}
        </div>
    );
}

ConnectMeta.layout = {
    breadcrumbs: [
        {
            title: 'Accounts',
            href: ConnectedAccountController.index().url,
        },
    ],
};

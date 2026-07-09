import { Head, router } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { PlatformName } from '@/types/compose';

type PlatformRow = {
    platform: PlatformName;
    label: string;
    enabled: boolean;
    configured: boolean;
};

type Props = {
    platforms: PlatformRow[];
};

export default function InstancePlatforms({ platforms }: Props) {
    function setPlatformEnabled(platform: PlatformName, enabled: boolean) {
        const values: Record<string, boolean> = {};

        for (const row of platforms) {
            values[row.platform] =
                row.platform === platform ? enabled : row.enabled;
        }

        router.put(
            InstanceSettingsController.updatePlatforms().url,
            { platforms: values },
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Instance platforms" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Platforms"
                    description="Freeze a platform instance-wide to stop publishing, polling, and new connections for it."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Available platforms</CardTitle>
                        <CardDescription>
                            Disabling a platform here overrides every workspace
                            on this instance.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {platforms.map((row) => (
                            <div
                                key={row.platform}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`platform-${row.platform}-enabled`}
                                    checked={row.enabled}
                                    onCheckedChange={(checked) =>
                                        setPlatformEnabled(
                                            row.platform,
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`platform-${row.platform}-enabled`}
                                >
                                    {row.label}
                                </Label>
                                {!row.configured && (
                                    <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                        Not configured
                                    </span>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

InstancePlatforms.layout = {
    breadcrumbs: [
        {
            title: 'Instance settings',
            href: InstanceSettingsController.edit().url,
        },
        {
            title: 'Platforms',
            href: InstanceSettingsController.platforms().url,
        },
    ],
};

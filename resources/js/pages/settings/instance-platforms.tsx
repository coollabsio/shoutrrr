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

type PlatformToggle = {
    platform: PlatformName;
    label: string;
    enabled: boolean;
    configured: boolean;
};

type Props = {
    platforms: PlatformToggle[];
};

export default function InstancePlatforms({ platforms }: Props) {
    function setEnabled(platform: PlatformName, enabled: boolean) {
        const next = Object.fromEntries(
            platforms.map((p) => [
                p.platform,
                p.platform === platform ? enabled : p.enabled,
            ]),
        );

        router.put(
            InstanceSettingsController.updatePlatforms().url,
            { platforms: next },
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
                    description="Turn a social platform on or off for everyone on this instance. Disabling a platform hides it from the composer, blocks new connections, and skips any scheduled posts to it."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Available platforms</CardTitle>
                        <CardDescription>
                            A disabled platform is frozen instance-wide.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {platforms.map((platform) => (
                            <div
                                key={platform.platform}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`platform-${platform.platform}`}
                                    checked={platform.enabled}
                                    onCheckedChange={(checked) =>
                                        setEnabled(
                                            platform.platform,
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`platform-${platform.platform}`}
                                >
                                    {platform.label}
                                </Label>
                                {!platform.configured && (
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

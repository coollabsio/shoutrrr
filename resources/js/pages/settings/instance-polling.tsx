import { Head, router, useForm } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PlatformName } from '@/types/compose';

type PollingGroup = Record<PlatformName, number> & {
    enabled: Record<PlatformName, boolean>;
};

export type PollingSettings = {
    engagement: PollingGroup;
    post_metrics: PollingGroup;
    account_metrics: PollingGroup;
};

type Props = {
    settings: PollingSettings;
};

const platforms: { key: PlatformName; label: string }[] = [
    { key: 'x', label: 'X' },
    { key: 'bluesky', label: 'Bluesky' },
    { key: 'linkedin', label: 'LinkedIn' },
    { key: 'facebook', label: 'Facebook' },
    { key: 'instagram', label: 'Instagram' },
    { key: 'threads', label: 'Threads' },
];

export function pollingWithMinutes(
    settings: PollingSettings,
    group: keyof PollingSettings,
    platform: PlatformName,
    value: string,
): PollingSettings {
    return {
        ...settings,
        [group]: {
            ...settings[group],
            [platform]: Number.parseInt(value || '0', 10),
        },
    };
}

export function pollingWithPlatformEnabled(
    settings: PollingSettings,
    group: keyof PollingSettings,
    platform: PlatformName,
    enabled: boolean,
): PollingSettings {
    return {
        ...settings,
        [group]: {
            ...settings[group],
            enabled: {
                ...settings[group].enabled,
                [platform]: enabled,
            },
        },
    };
}

export default function InstancePolling({ settings }: Props) {
    const { data, setData, put, processing, errors } =
        useForm<PollingSettings>(settings);

    function handleSubmit(event: React.FormEvent) {
        event.preventDefault();

        put(InstanceSettingsController.updatePolling().url, {
            preserveScroll: true,
        });
    }

    function setMinutes(
        group: keyof PollingSettings,
        platform: PlatformName,
        value: string,
    ) {
        setData(group, pollingWithMinutes(data, group, platform, value)[group]);
    }

    function setPlatformEnabled(
        group: keyof PollingSettings,
        platform: PlatformName,
        enabled: boolean,
    ) {
        const nextData = pollingWithPlatformEnabled(
            data,
            group,
            platform,
            enabled,
        );

        setData(group, nextData[group]);

        router.put(InstanceSettingsController.updatePolling().url, nextData, {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Instance polling" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Polling"
                    description="Tune how often each platform checks for replies and post metrics"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <PollingCard
                        title="Engagement"
                        description="How often to check published posts for new replies."
                        group="engagement"
                        values={data.engagement}
                        errors={errors}
                        onChange={setMinutes}
                        onEnabledChange={setPlatformEnabled}
                    />

                    <PollingCard
                        title="Post metrics"
                        description="How often to refresh likes, replies, reposts, and impressions for published posts."
                        group="post_metrics"
                        values={data.post_metrics}
                        errors={errors}
                        onChange={setMinutes}
                        onEnabledChange={setPlatformEnabled}
                    />

                    <PollingCard
                        title="Account metrics"
                        description="How often to snapshot follower, following, and post counts for connected accounts."
                        group="account_metrics"
                        values={data.account_metrics}
                        errors={errors}
                        onChange={setMinutes}
                        onEnabledChange={setPlatformEnabled}
                    />

                    <Button type="submit" disabled={processing}>
                        Save
                    </Button>
                </form>
            </div>
        </>
    );
}

function PollingCard({
    title,
    description,
    group,
    values,
    errors,
    onChange,
    onEnabledChange,
}: {
    title: string;
    description: string;
    group: keyof PollingSettings;
    values: PollingGroup;
    errors: Partial<Record<string, string>>;
    onChange: (
        group: keyof PollingSettings,
        platform: PlatformName,
        value: string,
    ) => void;
    onEnabledChange: (
        group: keyof PollingSettings,
        platform: PlatformName,
        enabled: boolean,
    ) => void;
}) {
    const hasDisabledPlatform = platforms.some(
        (platform) => !values.enabled[platform.key],
    );

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <CardTitle>{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    {hasDisabledPlatform && (
                        <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                            Partially disabled
                        </span>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {platforms.map((platform) => {
                    const errorKey = `${group}.${platform.key}`;
                    const isEnabled = values.enabled[platform.key];

                    return (
                        <div
                            key={platform.key}
                            className="grid gap-2 sm:grid-cols-[1fr_9rem] sm:items-start"
                        >
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={`${group}-${platform.key}-enabled`}
                                        checked={isEnabled}
                                        onCheckedChange={(checked) =>
                                            onEnabledChange(
                                                group,
                                                platform.key,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`${group}-${platform.key}-enabled`}
                                    >
                                        {platform.label}
                                    </Label>
                                    {!isEnabled && (
                                        <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                            Temporarily disabled
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {isEnabled
                                        ? 'Interval in minutes.'
                                        : `Polling for ${platform.label} is paused.`}
                                </p>
                            </div>
                            <div>
                                <Input
                                    id={`${group}-${platform.key}`}
                                    type="number"
                                    min={5}
                                    max={10080}
                                    step={5}
                                    value={values[platform.key]}
                                    disabled={!isEnabled}
                                    onChange={(event) =>
                                        onChange(
                                            group,
                                            platform.key,
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors[errorKey]} />
                            </div>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}

InstancePolling.layout = {
    breadcrumbs: [
        {
            title: 'Instance settings',
            href: InstanceSettingsController.edit().url,
        },
        {
            title: 'Polling',
            href: InstanceSettingsController.polling().url,
        },
    ],
};

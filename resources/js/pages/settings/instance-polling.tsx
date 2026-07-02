import { Head, useForm } from '@inertiajs/react';

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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PlatformName } from '@/types/compose';

type PollingGroup = Record<PlatformName, number>;

type PollingSettings = {
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
];

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
        setData(group, {
            ...data[group],
            [platform]: Number.parseInt(value || '0', 10),
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
                    />

                    <PollingCard
                        title="Post metrics"
                        description="How often to refresh likes, replies, reposts, and impressions for published posts."
                        group="post_metrics"
                        values={data.post_metrics}
                        errors={errors}
                        onChange={setMinutes}
                    />

                    <PollingCard
                        title="Account metrics"
                        description="How often to snapshot follower, following, and post counts for connected accounts."
                        group="account_metrics"
                        values={data.account_metrics}
                        errors={errors}
                        onChange={setMinutes}
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
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {platforms.map((platform) => {
                    const errorKey = `${group}.${platform.key}`;

                    return (
                        <div
                            key={platform.key}
                            className="grid gap-2 sm:grid-cols-[1fr_9rem] sm:items-start"
                        >
                            <div className="space-y-1">
                                <Label htmlFor={`${group}-${platform.key}`}>
                                    {platform.label}
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    Interval in minutes.
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

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
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

type PollingGroup = Record<PlatformName, number> & {
    enabled: Record<PlatformName, boolean>;
};

export type PollingSettings = {
    engagement: PollingGroup;
    post_metrics: PollingGroup;
    account_metrics: PollingGroup;
    /** Instance-wide master switches. When off, the matching section(s) below are moot. */
    metrics_enabled: boolean;
    engagement_enabled: boolean;
};

/** The three per-platform sections, excluding the two flat master-switch keys. */
type PollingSectionKey = 'engagement' | 'post_metrics' | 'account_metrics';

type SectionPlatform = { platform: PlatformName; label: string };

type Props = {
    settings: PollingSettings;
    sections: Record<PollingSectionKey, SectionPlatform[]>;
};

export function pollingWithMinutes(
    settings: PollingSettings,
    group: PollingSectionKey,
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
    group: PollingSectionKey,
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

export default function InstancePolling({ settings, sections }: Props) {
    const { data, setData, put, processing, errors } =
        useForm<PollingSettings>(settings);

    function handleSubmit(event: React.FormEvent) {
        event.preventDefault();

        put(InstanceSettingsController.updatePolling().url, {
            preserveScroll: true,
        });
    }

    function setMinutes(
        group: PollingSectionKey,
        platform: PlatformName,
        value: string,
    ) {
        setData(group, pollingWithMinutes(data, group, platform, value)[group]);
    }

    function setPlatformEnabled(
        group: PollingSectionKey,
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
                    <Card>
                        <CardHeader>
                            <CardTitle>Feature availability</CardTitle>
                            <CardDescription>
                                Turn engagement or metrics off for the whole
                                instance without touching environment variables.
                                The sections below only take effect while their
                                switch here is on.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="engagement_enabled"
                                    checked={data.engagement_enabled}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'engagement_enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="space-y-1">
                                    <Label htmlFor="engagement_enabled">
                                        Enable engagement
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        Governs the Engagement section below.
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="metrics_enabled"
                                    checked={data.metrics_enabled}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'metrics_enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="space-y-1">
                                    <Label htmlFor="metrics_enabled">
                                        Enable metrics
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        Governs both the Post metrics and
                                        Account metrics sections below.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <PollingCard
                        title="Engagement"
                        description="The minimum time between reply checks. Fresh posts are checked more often and back off as they age, so this sets the floor, not a fixed interval."
                        group="engagement"
                        platforms={sections.engagement}
                        values={data.engagement}
                        errors={errors}
                        onChange={setMinutes}
                        onEnabledChange={setPlatformEnabled}
                        minutesHelp="Minimum interval in minutes."
                        disabled={!data.engagement_enabled}
                    />

                    <PollingCard
                        title="Post metrics"
                        description="The minimum time between metric refreshes. Fresh posts are refreshed more often and back off as they age, so this sets the floor, not a fixed interval."
                        group="post_metrics"
                        platforms={sections.post_metrics}
                        values={data.post_metrics}
                        errors={errors}
                        onChange={setMinutes}
                        onEnabledChange={setPlatformEnabled}
                        minutesHelp="Minimum interval in minutes."
                        disabled={!data.metrics_enabled}
                    />

                    <PollingCard
                        title="Account metrics"
                        description="How often to snapshot follower, following, and post counts for connected accounts."
                        group="account_metrics"
                        platforms={sections.account_metrics}
                        values={data.account_metrics}
                        errors={errors}
                        onChange={setMinutes}
                        onEnabledChange={setPlatformEnabled}
                        disabled={!data.metrics_enabled}
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
    platforms,
    values,
    errors,
    onChange,
    onEnabledChange,
    minutesHelp = 'Interval in minutes.',
    disabled = false,
}: {
    title: string;
    description: string;
    group: PollingSectionKey;
    platforms: SectionPlatform[];
    values: PollingGroup;
    errors: Partial<Record<string, string>>;
    onChange: (
        group: PollingSectionKey,
        platform: PlatformName,
        value: string,
    ) => void;
    onEnabledChange: (
        group: PollingSectionKey,
        platform: PlatformName,
        enabled: boolean,
    ) => void;
    minutesHelp?: string;
    /** The instance-wide master switch for this section is off; every row below is moot. */
    disabled?: boolean;
}) {
    const hasDisabledPlatform = platforms.some(
        (p) => !values.enabled[p.platform],
    );

    return (
        <Card className={cn(disabled && 'opacity-60')}>
            <CardHeader>
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <CardTitle>{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    {disabled ? (
                        <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                            Disabled instance-wide
                        </span>
                    ) : (
                        hasDisabledPlatform && (
                            <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                Partially disabled
                            </span>
                        )
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {platforms.map((p) => {
                    const errorKey = `${group}.${p.platform}`;
                    const isEnabled = values.enabled[p.platform];

                    return (
                        <div
                            key={p.platform}
                            className="grid gap-2 sm:grid-cols-[1fr_9rem] sm:items-start"
                        >
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={`${group}-${p.platform}-enabled`}
                                        checked={isEnabled}
                                        disabled={disabled}
                                        onCheckedChange={(checked) =>
                                            onEnabledChange(
                                                group,
                                                p.platform,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`${group}-${p.platform}-enabled`}
                                    >
                                        {p.label}
                                    </Label>
                                    {!disabled && !isEnabled && (
                                        <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                            Temporarily disabled
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {isEnabled
                                        ? minutesHelp
                                        : `Polling for ${p.label} is paused.`}
                                </p>
                            </div>
                            <div>
                                <Input
                                    id={`${group}-${p.platform}`}
                                    type="number"
                                    min={5}
                                    max={10080}
                                    step={5}
                                    value={values[p.platform]}
                                    disabled={disabled || !isEnabled}
                                    onChange={(event) =>
                                        onChange(
                                            group,
                                            p.platform,
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

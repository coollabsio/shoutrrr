import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';

import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { preferences } from '@/routes/notifications';
import { update } from '@/routes/notifications/preferences';

type PreferencesMatrix = Record<string, { in_app: boolean; mail: boolean }>;

type PageProps = {
    preferences: PreferencesMatrix;
    alwaysOn: string[];
};

const EVENTS: { key: string; label: string; description: string }[] = [
    {
        key: 'post_published',
        label: 'Post published',
        description: 'When a scheduled post goes live.',
    },
    {
        key: 'publish_failed',
        label: 'Publish failed',
        description: 'When a post fails to publish.',
    },
    {
        key: 'workspace_invite',
        label: 'Workspace activity',
        description: 'Invitations and members joining.',
    },
    {
        key: 'account_needs_attention',
        label: 'Account needs attention',
        description: 'When a connected account must be reconnected.',
    },
];

export default function Notifications() {
    const { preferences: initialPreferences, alwaysOn } =
        usePage<PageProps>().props;

    const [prefs, setPrefs] = useState<PreferencesMatrix>(initialPreferences);
    const [processing, setProcessing] = useState(false);

    function toggleChannel(
        key: string,
        channel: 'in_app' | 'mail',
        value: boolean,
    ) {
        setPrefs((prev) => ({
            ...prev,
            [key]: {
                ...prev[key],
                [channel]: value,
            },
        }));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.put(
            update().url,
            { preferences: prefs },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose which events you want to be notified about"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="space-y-4">
                        <div className="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 gap-y-4 sm:gap-x-6">
                            <div />
                            <span className="text-sm font-medium text-muted-foreground">
                                In-app
                            </span>
                            <span className="text-sm font-medium text-muted-foreground">
                                Email
                            </span>

                            {EVENTS.map((event) => {
                                const isAlwaysOn = alwaysOn.includes(event.key);
                                const row = prefs[event.key] ?? {
                                    in_app: true,
                                    mail: true,
                                };

                                return (
                                    <Fragment key={event.key}>
                                        <div>
                                            <Label className="font-medium">
                                                {event.label}
                                            </Label>
                                            <p className="text-sm text-muted-foreground">
                                                {event.description}
                                            </p>
                                        </div>

                                        <div className="flex justify-center">
                                            <Checkbox
                                                id={`${event.key}-in_app`}
                                                checked={
                                                    isAlwaysOn
                                                        ? true
                                                        : row.in_app
                                                }
                                                disabled={isAlwaysOn}
                                                onCheckedChange={(checked) => {
                                                    if (!isAlwaysOn) {
                                                        toggleChannel(
                                                            event.key,
                                                            'in_app',
                                                            checked === true,
                                                        );
                                                    }
                                                }}
                                            />
                                            {isAlwaysOn && (
                                                <span className="sr-only">
                                                    Always on
                                                </span>
                                            )}
                                        </div>

                                        <div className="flex justify-center">
                                            <Checkbox
                                                id={`${event.key}-mail`}
                                                checked={row.mail}
                                                onCheckedChange={(checked) => {
                                                    toggleChannel(
                                                        event.key,
                                                        'mail',
                                                        checked === true,
                                                    );
                                                }}
                                            />
                                        </div>
                                    </Fragment>
                                );
                            })}
                        </div>
                    </div>

                    <Button type="submit" disabled={processing}>
                        Save
                    </Button>
                </form>
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: preferences(),
        },
    ],
};

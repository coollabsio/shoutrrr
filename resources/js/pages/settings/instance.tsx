import { Head, useForm } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type InstanceSettings = {
    registrations_enabled: boolean;
    workspace_creation_enabled: boolean;
    usage_tracking_enabled: boolean;
    quote_tweets_enabled: boolean;
    external_posts_sync_lookback_days: number;
};

type PageProps = {
    settings: InstanceSettings;
    workspaces_enabled: boolean;
};

export default function Instance({ settings, workspaces_enabled }: PageProps) {
    const { data, setData, put, processing } = useForm<InstanceSettings>({
        registrations_enabled: settings.registrations_enabled,
        workspace_creation_enabled:
            workspaces_enabled && settings.workspace_creation_enabled,
        usage_tracking_enabled: settings.usage_tracking_enabled,
        quote_tweets_enabled: settings.quote_tweets_enabled,
        external_posts_sync_lookback_days:
            settings.external_posts_sync_lookback_days,
    });

    function handleSubmit(event: React.FormEvent) {
        event.preventDefault();

        put(InstanceSettingsController.update().url, {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Instance settings" />

            <h1 className="sr-only">Instance settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="General"
                    description="Control signups and workspace creation for this instance"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="space-y-4">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="registrations_enabled"
                                checked={data.registrations_enabled}
                                onCheckedChange={(checked) =>
                                    setData(
                                        'registrations_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            <div className="space-y-1">
                                <Label htmlFor="registrations_enabled">
                                    Allow public registration
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    When disabled, new users can only join with
                                    a workspace invitation.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="workspace_creation_enabled"
                                checked={data.workspace_creation_enabled}
                                disabled={!workspaces_enabled}
                                onCheckedChange={(checked) =>
                                    setData(
                                        'workspace_creation_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            <div className="space-y-1">
                                <Label htmlFor="workspace_creation_enabled">
                                    Allow users to create workspaces
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    {workspaces_enabled
                                        ? 'When disabled, users can still access workspaces they already belong to.'
                                        : 'Workspace creation is unavailable because workspaces are disabled by the WORKSPACES_ENABLED environment setting.'}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="usage_tracking_enabled"
                                checked={data.usage_tracking_enabled}
                                onCheckedChange={(checked) =>
                                    setData(
                                        'usage_tracking_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            <div className="space-y-1">
                                <Label htmlFor="usage_tracking_enabled">
                                    Track platform API usage
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    When enabled, records per-workspace API
                                    usage (posts, reads, requests) for cost and
                                    abuse monitoring. Off by default.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="quote_tweets_enabled"
                                checked={data.quote_tweets_enabled}
                                onCheckedChange={(checked) =>
                                    setData(
                                        'quote_tweets_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            <div className="space-y-1">
                                <Label htmlFor="quote_tweets_enabled">
                                    Quote tweets on X from pasted links
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    When enabled, a post link in an X update
                                    becomes a quote tweet instead of a plain
                                    link. Requires X Enterprise API access;
                                    leave off on other tiers. Off by default.
                                </p>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="external_posts_sync_lookback_days">
                                X sync lookback
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="external_posts_sync_lookback_days"
                                    type="number"
                                    min={1}
                                    max={365}
                                    className="w-24"
                                    value={
                                        data.external_posts_sync_lookback_days
                                    }
                                    onChange={(event) => {
                                        const value = Number.parseInt(
                                            event.currentTarget.value,
                                            10,
                                        );

                                        setData(
                                            'external_posts_sync_lookback_days',
                                            Number.isNaN(value) ? 90 : value,
                                        );
                                    }}
                                />
                                <span className="text-sm text-muted-foreground">
                                    days
                                </span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Connected X accounts import only posts created
                                inside this window. Default is 90 days.
                            </p>
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

Instance.layout = {
    breadcrumbs: [
        {
            title: 'Instance settings',
            href: InstanceSettingsController.edit().url,
        },
    ],
};

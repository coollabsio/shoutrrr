import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';

import NativeTrackingController from '@/actions/App/Http/Controllers/Settings/NativeTrackingController';
import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import { AccountAvatar } from '@/components/common/account-avatar';
import { useConfirm } from '@/components/common/confirm-dialog';
import CreateSyncPipelineDialog, {
    type SyncAccount,
} from '@/components/settings/create-sync-pipeline-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { Switch } from '@/components/ui/switch';

type Pipeline = {
    id: string;
    name: string;
    enabled: boolean;
    source_connected_account_id: string;
    destination_connected_account_ids: string[];
};

/** Avatar + name with the @handle in muted, so same-named accounts are distinguishable. */
function AccountChip({ account }: { account: SyncAccount }) {
    const name = account.display_name ?? account.handle;
    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            <AccountAvatar
                platform={account.platform}
                handle={account.handle}
                avatarUrl={account.avatar_url}
                ringClassName="ring-card"
            />
            <span className="truncate font-medium text-foreground">{name}</span>
            {account.handle !== name && (
                <span className="truncate text-muted-foreground">
                    {account.handle}
                </span>
            )}
        </span>
    );
}

type Props = {
    accounts: SyncAccount[];
    pipelines: Pipeline[];
    maxPipelines: number;
    canCreate: boolean;
    trackableAccounts: SyncAccount[];
    trackedAccountIds: string[];
    canTrack: boolean;
    maxTracked: number;
};

export default function SyncPipelines({
    accounts,
    pipelines,
    maxPipelines,
    canCreate,
    trackableAccounts,
    trackedAccountIds,
    canTrack,
    maxTracked,
}: Props) {
    const confirm = useConfirm();

    function accountById(id: string): SyncAccount | undefined {
        return accounts.find((a) => a.id === id);
    }

    function accountName(account: SyncAccount): string {
        return account.display_name ?? account.handle;
    }

    function toggle(pipeline: Pipeline, enabled: boolean) {
        router.patch(
            SyncPipelinesController.update(pipeline.id).url,
            { enabled },
            { preserveScroll: true },
        );
    }

    function toggleTracking(accountId: string, enabled: boolean) {
        if (enabled) {
            router.post(
                NativeTrackingController.store(accountId).url,
                {},
                { preserveScroll: true },
            );
        } else {
            router.delete(NativeTrackingController.destroy(accountId).url, {
                preserveScroll: true,
            });
        }
    }

    async function remove(pipeline: Pipeline) {
        const confirmed = await confirm({
            title: 'Delete sync pipeline?',
            description: `“${pipeline.name}” will stop reposting.`,
            destructive: true,
        });
        if (confirmed) {
            router.delete(SyncPipelinesController.destroy(pipeline.id).url, {
                preserveScroll: true,
                onSuccess: () => toast.success('Pipeline deleted'),
            });
        }
    }

    return (
        <>
            <Head title="Sync pipelines" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Sync pipelines</CardTitle>
                        <CardDescription>
                            Automatically repost a published post to other
                            accounts. {pipelines.length}/{maxPipelines} used.
                        </CardDescription>
                        <CardAction>
                            <CreateSyncPipelineDialog
                                accounts={accounts}
                                disabled={!canCreate}
                            />
                        </CardAction>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {pipelines.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>No pipelines yet</EmptyTitle>
                                    <EmptyDescription>
                                        Create one to mirror your posts across
                                        accounts.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            pipelines.map((pipeline) => {
                                const source = accountById(
                                    pipeline.source_connected_account_id,
                                );
                                return (
                                    <div
                                        key={pipeline.id}
                                        className="flex items-center justify-between rounded-lg border p-4"
                                    >
                                        <div className="grid min-w-0 gap-2.5">
                                            <p className="font-medium">
                                                {pipeline.name}
                                            </p>
                                            <div className="grid gap-1.5 text-sm">
                                                <div className="flex items-center gap-2.5">
                                                    <span className="w-8 shrink-0 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                                        From
                                                    </span>
                                                    {source && (
                                                        <AccountChip
                                                            account={source}
                                                        />
                                                    )}
                                                </div>
                                                <div className="flex items-start gap-2.5">
                                                    <span className="mt-1 w-8 shrink-0 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                                        To
                                                    </span>
                                                    <div className="flex min-w-0 flex-col gap-1.5">
                                                        {pipeline.destination_connected_account_ids
                                                            .map((id) =>
                                                                accountById(id),
                                                            )
                                                            .filter(
                                                                (dest) =>
                                                                    dest !==
                                                                    undefined,
                                                            )
                                                            .map((dest) => (
                                                                <AccountChip
                                                                    key={
                                                                        dest.id
                                                                    }
                                                                    account={
                                                                        dest
                                                                    }
                                                                />
                                                            ))}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <Switch
                                                checked={pipeline.enabled}
                                                aria-label={`Enable sync pipeline ${pipeline.name}`}
                                                onCheckedChange={(v) =>
                                                    toggle(pipeline, v)
                                                }
                                            />
                                            <Button
                                                variant="ghost"
                                                onClick={() => remove(pipeline)}
                                            >
                                                Delete
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Native tracking</CardTitle>
                        <CardDescription>
                            Watch an account for posts made directly on the
                            platform so they can sync too.{' '}
                            {trackedAccountIds.length}/{maxTracked} used.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {trackableAccounts.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        No trackable accounts
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Connect an account on a platform that
                                        supports native tracking.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            trackableAccounts.map((account) => {
                                const tracked = trackedAccountIds.includes(
                                    account.id,
                                );
                                return (
                                    <div
                                        key={account.id}
                                        className="flex items-center justify-between rounded-lg border p-4"
                                    >
                                        <div className="flex items-center gap-3">
                                            <AccountAvatar
                                                platform={account.platform}
                                                handle={account.handle}
                                                avatarUrl={account.avatar_url}
                                                size="md"
                                                ringClassName="ring-card"
                                            />
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {accountName(account)}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {account.handle}
                                                </p>
                                            </div>
                                        </div>
                                        <Switch
                                            checked={tracked}
                                            disabled={!canTrack && !tracked}
                                            aria-label={`Track ${account.display_name ?? account.handle}`}
                                            onCheckedChange={(v) =>
                                                toggleTracking(account.id, v)
                                            }
                                        />
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

SyncPipelines.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: WorkspaceSettingsController.showOverview().url,
        },
        {
            title: 'Sync pipelines',
            href: SyncPipelinesController.index().url,
        },
    ],
};

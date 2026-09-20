import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';

import NativeTrackingController from '@/actions/App/Http/Controllers/Settings/NativeTrackingController';
import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import { AccountAvatar } from '@/components/common/account-avatar';
import { useConfirm } from '@/components/common/confirm-dialog';
import CreateSyncPipelineDialog, {
    type SyncAccount,
} from '@/components/settings/create-sync-pipeline-dialog';
import { Button } from '@/components/ui/button';
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
            <Head title="Sync" />
            <div className="mx-auto grid w-full max-w-2xl gap-10 p-4 sm:p-6">
                <header className="grid gap-1.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Sync
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Post once and Shoutrrr reposts it to your other accounts
                        automatically.
                    </p>
                </header>

                <section className="grid gap-4">
                    <div className="flex items-start justify-between gap-4">
                        <div className="grid gap-1">
                            <div className="flex items-center gap-2">
                                <h2 className="font-medium">Pipelines</h2>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                                    {pipelines.length}/{maxPipelines}
                                </span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Each pipeline reposts everything you publish
                                from one account to the others you pick.
                            </p>
                        </div>
                        <CreateSyncPipelineDialog
                            accounts={accounts}
                            disabled={!canCreate}
                            trackedAccountIds={trackedAccountIds}
                            canTrack={canTrack}
                            maxTracked={maxTracked}
                        />
                    </div>

                    {pipelines.length === 0 ? (
                        <Empty className="rounded-xl border border-dashed">
                            <EmptyHeader>
                                <EmptyTitle>No pipelines yet</EmptyTitle>
                                <EmptyDescription>
                                    Create one to automatically repost from a
                                    source account to your others — e.g. X →
                                    LinkedIn &amp; Bluesky.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="grid gap-2.5">
                            {pipelines.map((pipeline) => {
                                const source = accountById(
                                    pipeline.source_connected_account_id,
                                );
                                const untrackedSource =
                                    source?.supports_native === true &&
                                    !trackedAccountIds.includes(source.id);
                                return (
                                    <div
                                        key={pipeline.id}
                                        className="grid gap-3 rounded-xl border p-4 transition-colors hover:border-border/80"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="min-w-0 truncate font-medium">
                                                {pipeline.name}
                                            </p>
                                            <div className="flex shrink-0 items-center gap-1">
                                                <Switch
                                                    checked={pipeline.enabled}
                                                    aria-label={`Enable the ${pipeline.name} pipeline`}
                                                    onCheckedChange={(v) =>
                                                        toggle(pipeline, v)
                                                    }
                                                />
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-muted-foreground"
                                                    onClick={() =>
                                                        remove(pipeline)
                                                    }
                                                >
                                                    Delete
                                                </Button>
                                            </div>
                                        </div>

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
                                                                key={dest.id}
                                                                account={dest}
                                                            />
                                                        ))}
                                                </div>
                                            </div>
                                        </div>

                                        {untrackedSource && (
                                            <p className="border-t pt-2.5 text-xs text-muted-foreground">
                                                Only posts you publish through
                                                Shoutrrr sync.{' '}
                                                {canTrack ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggleTracking(
                                                                source.id,
                                                                true,
                                                            )
                                                        }
                                                        className="font-medium text-foreground underline-offset-2 hover:underline"
                                                    >
                                                        Track native posts too
                                                    </button>
                                                ) : (
                                                    'Native tracking is full — untrack an account to include native posts.'
                                                )}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>

                <section className="grid gap-4 border-t pt-8">
                    <div className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <h2 className="font-medium">
                                Posts made outside Shoutrrr
                            </h2>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                                {trackedAccountIds.length}/{maxTracked}
                            </span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Pipelines only see posts you publish through
                            Shoutrrr. Track an account to also sync — and pull
                            in analytics for — posts you make directly on the
                            platform.
                        </p>
                    </div>

                    {trackableAccounts.length === 0 ? (
                        <Empty className="rounded-xl border border-dashed">
                            <EmptyHeader>
                                <EmptyTitle>No trackable accounts</EmptyTitle>
                                <EmptyDescription>
                                    Connect an account on a platform we can
                                    watch — X, Bluesky, Facebook, Instagram, or
                                    Threads.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="grid gap-2.5">
                            {trackableAccounts.map((account) => {
                                const tracked = trackedAccountIds.includes(
                                    account.id,
                                );
                                const feeds = pipelines.filter(
                                    (p) =>
                                        p.source_connected_account_id ===
                                        account.id,
                                );
                                return (
                                    <div
                                        key={account.id}
                                        className="flex items-center justify-between gap-3 rounded-xl border p-3"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
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
                                                <p className="truncate text-sm text-muted-foreground">
                                                    {feeds.length > 0
                                                        ? `Source of ${feeds.map((f) => f.name).join(', ')}`
                                                        : account.handle}
                                                </p>
                                            </div>
                                        </div>
                                        <Switch
                                            checked={tracked}
                                            disabled={!canTrack && !tracked}
                                            aria-label={`Track posts from ${accountName(account)}`}
                                            onCheckedChange={(v) =>
                                                toggleTracking(account.id, v)
                                            }
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

SyncPipelines.layout = {
    breadcrumbs: [
        {
            title: 'Sync pipelines',
            href: SyncPipelinesController.index().url,
        },
    ],
};

import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import { GettingStartedCard } from '@/components/onboarding/getting-started-card';
import { WelcomeModal } from '@/components/onboarding/welcome-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
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
import {
    Bot,
    Check,
    Copy,
    KeyRound,
    Plug,
    RefreshCw,
} from '@/components/ui/icons';
import { dayjs } from '@/lib/datetime/dayjs';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { home as agenticHome } from '@/routes/agentic';
import type { OnboardingData } from '@/types';

type ActivityItem = {
    id: string;
    kind: string;
    label: string;
    detail: string | null;
    succeeded: boolean | null;
    occurred_at: string;
};

type Props = {
    onboarding: OnboardingData | null;
    mcp: {
        endpoint: string;
        docs_url: string;
        snippet: string;
    };
    api: {
        base_url: string;
        publish_example: string;
        schedule_example: string;
    };
    sync: {
        total: number;
        enabled: number;
    };
    activity: ActivityItem[];
};

function CopyBlock({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        if (!navigator.clipboard) {
            toast.error(
                'Copy is unavailable here — select the text and copy it manually.',
            );
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            toast.success('Copied to clipboard');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Copy failed — select the text and copy it manually.');
        }
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
                <p className="text-xs font-medium text-muted-foreground">
                    {label}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    onClick={copy}
                >
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
            <pre className="overflow-x-auto rounded-xl bg-muted/60 p-3 font-mono text-xs leading-5 text-foreground">
                {value}
            </pre>
        </div>
    );
}

export default function AgenticHome({
    onboarding,
    mcp,
    api,
    sync,
    activity,
}: Props) {
    const { auth, shell, workspaces } = usePage().props;
    const firstName = (auth.user?.name ?? '').split(/\s+/)[0] || 'there';
    const accounts = shell?.accounts ?? [];
    const canManageWorkspace = (workspaces.current?.permissions ?? []).includes(
        'workspace.settings.manage',
    );
    const syncSummary =
        sync.total === 0
            ? 'No sync pipelines yet.'
            : `${sync.enabled} of ${sync.total} ${sync.total === 1 ? 'pipeline' : 'pipelines'} enabled.`;

    return (
        <>
            <Head title="Agentic" />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 pt-6 pb-16 sm:px-6">
                {onboarding && <WelcomeModal welcomed={onboarding.welcomed} />}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="inline-flex items-center gap-1.5 text-xs font-medium tracking-wide text-primary uppercase">
                            <Bot className="size-3.5" />
                            Agentic
                        </p>
                        <h1 className="mt-1 text-[26px] leading-tight font-semibold tracking-tight">
                            Welcome, {firstName}
                        </h1>
                        <p className="mt-1.5 max-w-2xl text-[13.5px] tracking-tight text-muted-foreground">
                            Agentic is the default home. Connect accounts, hand
                            an agent the MCP endpoint or the publish and
                            schedule intents, and check sync. Classic — the
                            composer, calendar, queue, and inbox — stays a full
                            mode. Choose it in the sidebar whenever you want
                            that as your home.
                        </p>
                    </div>
                    <Button
                        nativeButton={false}
                        variant="outline"
                        render={<Link href={dashboard()} />}
                    >
                        Open Classic composer
                    </Button>
                </div>

                {onboarding && <GettingStartedCard onboarding={onboarding} />}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Plug className="size-4 text-primary" />
                                Connected accounts
                            </CardTitle>
                            <CardDescription>
                                Agents and Classic publish to the same workspace
                                accounts.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {accounts.length === 0 ? (
                                <Empty className="border border-dashed">
                                    <EmptyHeader>
                                        <EmptyTitle>No accounts yet</EmptyTitle>
                                        <EmptyDescription>
                                            Connect a destination before an
                                            agent can publish.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {accounts.slice(0, 6).map((account) => (
                                        <li
                                            key={account.id}
                                            className="flex items-center justify-between gap-3 text-sm"
                                        >
                                            <span className="truncate font-medium">
                                                {account.display_name ||
                                                    account.handle}
                                            </span>
                                            <span className="shrink-0 text-muted-foreground">
                                                {account.platform}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <Button
                                nativeButton={false}
                                variant="outline"
                                className="w-fit"
                                render={<Link href={accountsRoute()} />}
                            >
                                {accounts.length === 0
                                    ? 'Connect an account'
                                    : 'Manage accounts'}
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <RefreshCw className="size-4 text-primary" />
                                Sync
                            </CardTitle>
                            <CardDescription>
                                Sync-first still runs across both modes.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <p className="text-sm">{syncSummary}</p>
                            {canManageWorkspace ? (
                                <Button
                                    nativeButton={false}
                                    variant="outline"
                                    className="w-fit"
                                    render={
                                        <Link
                                            href={SyncPipelinesController.index()}
                                        />
                                    }
                                >
                                    {sync.total === 0
                                        ? 'Set up sync'
                                        : 'Manage sync pipelines'}
                                </Button>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    A workspace admin sets up sync pipelines.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <KeyRound className="size-4 text-primary" />
                                MCP and API
                            </CardTitle>
                            <CardDescription>
                                Point an agent at the MCP endpoint, or call the
                                idempotent intents with a write API key.
                                Repeating the same Idempotency-Key returns the
                                original post instead of publishing twice.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-5">
                            <CopyBlock
                                label="MCP endpoint"
                                value={mcp.endpoint}
                            />
                            <CopyBlock
                                label="Client config"
                                value={mcp.snippet}
                            />
                            <CopyBlock
                                label="Publish now"
                                value={api.publish_example}
                            />
                            <CopyBlock
                                label="Schedule at"
                                value={api.schedule_example}
                            />
                            <p className="text-xs text-muted-foreground">
                                REST base {api.base_url}. MCP tools are{' '}
                                <code className="font-mono">publish_now</code>{' '}
                                and{' '}
                                <code className="font-mono">schedule_at</code>.{' '}
                                <a
                                    href={mcp.docs_url}
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Read the MCP docs
                                </a>
                                .
                            </p>
                            {canManageWorkspace ? (
                                <Button
                                    nativeButton={false}
                                    variant="outline"
                                    className="w-fit"
                                    render={
                                        <Link
                                            href={ApiKeysController.index()}
                                        />
                                    }
                                >
                                    Manage API keys
                                </Button>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    A workspace admin creates API keys for this
                                    workspace.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Recent agent activity</CardTitle>
                            <CardDescription>
                                Publish and schedule intents, plus MCP calls for
                                this workspace.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {activity.length === 0 ? (
                                <Empty className="border border-dashed">
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            No agent activity yet
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            When an agent connects over MCP or
                                            calls publish now / schedule at, it
                                            shows up here.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {activity.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex items-center justify-between gap-3 text-sm"
                                        >
                                            <span className="min-w-0">
                                                <span className="font-medium">
                                                    {item.label}
                                                </span>
                                                {item.detail && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {item.detail}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                {item.succeeded === false && (
                                                    <Badge variant="destructive">
                                                        Failed
                                                    </Badge>
                                                )}
                                                {item.succeeded === true && (
                                                    <Badge variant="success">
                                                        OK
                                                    </Badge>
                                                )}
                                                <span className="text-muted-foreground">
                                                    {dayjs(
                                                        item.occurred_at,
                                                    ).fromNow()}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

AgenticHome.layout = {
    breadcrumbs: [
        {
            title: 'Agentic',
            href: agenticHome(),
        },
    ],
};

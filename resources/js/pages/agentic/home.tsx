import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ChevronDown } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import { dayjs } from '@/lib/datetime/dayjs';
import { index as accountsRoute } from '@/routes/accounts';
import { home as agenticHome } from '@/routes/agentic';

type ActivityItem = {
    id: string;
    kind: string;
    label: string;
    detail: string | null;
    succeeded: boolean | null;
    occurred_at: string;
};

type Props = {
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
    activity: ActivityItem[];
};

export default function AgenticHome({ mcp, api, activity }: Props) {
    const { workspaces } = usePage().props;
    const canManageWorkspace = (workspaces.current?.permissions ?? []).includes(
        'workspace.settings.manage',
    );
    const [copied, setCopied] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);

    async function connect() {
        if (!navigator.clipboard) {
            toast.error('Copy the URL manually.');
            return;
        }

        try {
            await navigator.clipboard.writeText(mcp.endpoint);
            setCopied(true);
            toast.success('Copied. Paste it in your agent.');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Copy the URL manually.');
        }
    }

    return (
        <>
            <Head title="Agentic" />
            <div className="mx-auto flex w-full max-w-lg flex-col gap-8 px-4 pt-10 pb-16">
                <section className="flex flex-col gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Connect with MCP
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        OAuth. Paste this URL in your agent. It can hand you
                        connect links; you still approve each network in the
                        browser.
                    </p>
                    <div className="flex gap-2">
                        <Input
                            readOnly
                            value={mcp.endpoint}
                            aria-label="MCP URL"
                            onFocus={(event) => event.target.select()}
                            className="font-mono text-xs"
                        />
                        <Button
                            type="button"
                            className="shrink-0"
                            onClick={connect}
                        >
                            {copied ? 'Copied' : 'Connect with MCP'}
                        </Button>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium">Activity</h2>
                    {activity.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            None yet
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {activity.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-baseline justify-between gap-3 text-sm"
                                >
                                    <span className="truncate">
                                        {item.label}
                                        {item.succeeded === false
                                            ? ' · failed'
                                            : ''}
                                    </span>
                                    <span className="shrink-0 text-muted-foreground">
                                        {dayjs(item.occurred_at).fromNow()}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <Link
                    href={accountsRoute()}
                    className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Connect accounts
                </Link>

                <Collapsible open={moreOpen} onOpenChange={setMoreOpen}>
                    <CollapsibleTrigger
                        render={
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="px-0 text-muted-foreground"
                            />
                        }
                    >
                        API keys
                        <ChevronDown
                            aria-hidden="true"
                            className={
                                moreOpen
                                    ? 'size-4 rotate-180 transition-transform'
                                    : 'size-4 transition-transform'
                            }
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="flex flex-col gap-3 pt-3">
                        <pre className="overflow-x-auto font-mono text-xs leading-5 text-muted-foreground">
                            {api.publish_example}
                        </pre>
                        <pre className="overflow-x-auto font-mono text-xs leading-5 text-muted-foreground">
                            {api.schedule_example}
                        </pre>
                        {canManageWorkspace ? (
                            <Link
                                href={ApiKeysController.index()}
                                className="text-sm underline-offset-4 hover:underline"
                            >
                                API keys
                            </Link>
                        ) : null}
                        <a
                            href={mcp.docs_url}
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Docs
                        </a>
                    </CollapsibleContent>
                </Collapsible>
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

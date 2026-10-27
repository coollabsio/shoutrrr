import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Check, ChevronDown, Copy, RefreshCw } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useClipboard } from '@/hooks/use-clipboard';
import { dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';
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
    };
    api: {
        base_url: string;
        publish_example: string;
        schedule_example: string;
    };
    setup: {
        accounts: number;
        agent_connected: boolean;
    };
    sync: {
        total: number;
        enabled: number;
    };
    activity: ActivityItem[];
};

type ClientKey = 'claude' | 'chatgpt' | 'claude-code' | 'cursor' | 'other';

const CLIENTS: { key: ClientKey; label: string }[] = [
    { key: 'claude', label: 'Claude' },
    { key: 'chatgpt', label: 'ChatGPT' },
    { key: 'claude-code', label: 'Claude Code' },
    { key: 'cursor', label: 'Cursor' },
    { key: 'other', label: 'Other' },
];

const EXAMPLE_PROMPTS = [
    'Help me connect my Bluesky account to Shoutrrr.',
    'Post "We just shipped a new release!" to all my accounts.',
    'Schedule a post for tomorrow at 9:00 that says "Good morning!".',
    'What posts do I have scheduled this week?',
];

const SYNC_PROMPT =
    'Set up a sync: when I post on X, also post it to Bluesky and LinkedIn.';

export default function AgenticHome({
    mcp,
    api,
    setup,
    sync,
    activity,
}: Props) {
    const { workspaces } = usePage().props;
    const canManageWorkspace = (workspaces.current?.permissions ?? []).includes(
        'workspace.settings.manage',
    );
    const [client, setClient] = useState<ClientKey>('claude');
    const [developerOpen, setDeveloperOpen] = useState(false);

    return (
        <>
            <Head title="Get started" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-10 px-4 pt-10 pb-16">
                <header className="flex flex-col gap-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Post to social media from your AI assistant
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Connect Shoutrrr to Claude, ChatGPT, or another AI app.
                        Then ask it to write, post, or schedule for you, in
                        plain words.
                    </p>
                </header>

                <ol className="flex flex-col gap-4">
                    <Step
                        number={1}
                        done={setup.accounts > 0}
                        title="Connect your social accounts"
                        description={
                            setup.accounts > 0
                                ? `${setup.accounts} ${setup.accounts === 1 ? 'account is' : 'accounts are'} connected. You can add more at any time.`
                                : 'Choose where your posts go: X, LinkedIn, Bluesky, Facebook, Instagram, Threads, or Discord.'
                        }
                    >
                        <Button
                            nativeButton={false}
                            variant={setup.accounts > 0 ? 'outline' : 'default'}
                            className="self-start"
                            render={<Link href={accountsRoute()} />}
                        >
                            {setup.accounts > 0
                                ? 'Manage accounts'
                                : 'Connect accounts'}
                        </Button>
                    </Step>

                    <Step
                        number={2}
                        done={setup.agent_connected}
                        title="Add Shoutrrr to your AI assistant"
                        description={
                            setup.agent_connected
                                ? 'An assistant is connected. To add another app, follow the same steps.'
                                : 'Pick the app you use and follow the steps. You sign in to Shoutrrr one time to approve it.'
                        }
                    >
                        <ToggleGroup
                            value={[client]}
                            onValueChange={(value) => {
                                const next = value[0] as ClientKey | undefined;
                                if (next) {
                                    setClient(next);
                                }
                            }}
                            variant="outline"
                            size="sm"
                            className="flex-wrap"
                        >
                            {CLIENTS.map((item) => (
                                <ToggleGroupItem
                                    key={item.key}
                                    value={item.key}
                                    className="px-3 text-xs"
                                >
                                    {item.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                        <ClientInstructions
                            client={client}
                            endpoint={mcp.endpoint}
                        />
                    </Step>

                    <Step
                        number={3}
                        done={false}
                        title="Ask your assistant to post"
                        description="Talk to it like you talk to a person. Before it publishes, it asks you to confirm. Some examples:"
                    >
                        <ul className="flex flex-col gap-2">
                            {EXAMPLE_PROMPTS.map((prompt) => (
                                <PromptRow key={prompt} prompt={prompt} />
                            ))}
                        </ul>
                    </Step>
                </ol>

                <section className="flex flex-col gap-4 rounded-2xl border border-primary/40 bg-primary/5 p-5">
                    <div className="flex gap-4">
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                            <RefreshCw className="size-4" aria-hidden="true" />
                        </span>
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <h2 className="font-medium">
                                Post once, show up everywhere
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {sync.total > 0
                                    ? `${sync.enabled} of ${sync.total} sync ${sync.total === 1 ? 'pipeline is' : 'pipelines are'} on. Each post on a source account is copied to its other accounts.`
                                    : 'A sync copies each post from one account to up to three others. Ask your assistant to set one up:'}
                            </p>
                        </div>
                    </div>
                    <ul className="flex flex-col gap-2 pl-11">
                        <PromptRow prompt={SYNC_PROMPT} />
                    </ul>
                    {canManageWorkspace ? (
                        <Button
                            nativeButton={false}
                            variant={sync.total > 0 ? 'outline' : 'default'}
                            className="ml-11 self-start"
                            render={
                                <Link href={SyncPipelinesController.index()} />
                            }
                        >
                            {sync.total > 0 ? 'Manage syncs' : 'Set up a sync'}
                        </Button>
                    ) : null}
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium">Recent activity</h2>
                    {activity.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing yet. When your assistant posts or schedules
                            something, it shows here.
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
                                        {item.succeeded === false ? (
                                            <span className="text-destructive">
                                                {' '}
                                                · failed
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="shrink-0 text-muted-foreground">
                                        {dayjs(item.occurred_at).fromNow()}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <Collapsible
                    open={developerOpen}
                    onOpenChange={setDeveloperOpen}
                >
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
                        For developers: use the REST API
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-4 transition-transform',
                                developerOpen && 'rotate-180',
                            )}
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="flex flex-col gap-3 pt-3">
                        <p className="text-sm text-muted-foreground">
                            Scripts and your own tools can post without an AI
                            assistant. Create an API key, then send requests to{' '}
                            <code className="font-mono text-xs">
                                {api.base_url}
                            </code>
                            . Use a new Idempotency-Key for each post, so a
                            retry does not post twice.
                        </p>
                        <CodeBlock value={api.publish_example} />
                        <CodeBlock value={api.schedule_example} />
                        <div className="flex gap-4 text-sm">
                            {canManageWorkspace ? (
                                <Link
                                    href={ApiKeysController.index()}
                                    className="underline-offset-4 hover:underline"
                                >
                                    Create an API key
                                </Link>
                            ) : null}
                            <a
                                href={mcp.docs_url}
                                className="text-muted-foreground underline-offset-4 hover:underline"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Read the docs
                            </a>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </>
    );
}

function Step({
    number,
    done,
    title,
    description,
    children,
}: {
    number: number;
    done: boolean;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <li className="flex gap-4 rounded-2xl border bg-card p-5">
            <span
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-sm font-medium',
                    done && 'border-primary bg-primary text-primary-foreground',
                )}
            >
                {done ? <Check className="size-4" aria-label="Done" /> : number}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <h2 className="font-medium">{title}</h2>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {children}
            </div>
        </li>
    );
}

function ClientInstructions({
    client,
    endpoint,
}: {
    client: ClientKey;
    endpoint: string;
}) {
    const url = <CopyField value={endpoint} label="Shoutrrr URL" />;

    switch (client) {
        case 'claude':
            return (
                <Instructions>
                    <li>
                        In Claude, open <b>Settings</b>, then <b>Connectors</b>.
                    </li>
                    <li>
                        Click <b>Add custom connector</b>. Type <b>Shoutrrr</b>{' '}
                        as the name and paste this URL:
                        {url}
                    </li>
                    <li>
                        Click <b>Connect</b>, then sign in to Shoutrrr and
                        approve.
                    </li>
                </Instructions>
            );
        case 'chatgpt':
            return (
                <Instructions>
                    <li>
                        In ChatGPT, open <b>Settings</b>, then <b>Apps</b>, then{' '}
                        <b>Advanced settings</b>. Turn on <b>Developer mode</b>.
                    </li>
                    <li>
                        Click <b>Create</b>. Type <b>Shoutrrr</b> as the name,
                        paste this URL, and select <b>OAuth</b>:{url}
                    </li>
                    <li>Sign in to Shoutrrr and approve.</li>
                </Instructions>
            );
        case 'claude-code':
            return (
                <Instructions>
                    <li>
                        In a terminal, run:
                        <CopyField
                            value={`claude mcp add --transport http shoutrrr ${endpoint}`}
                        />
                    </li>
                    <li>
                        In Claude Code, type <b>/mcp</b>, select <b>shoutrrr</b>
                        , then sign in to Shoutrrr and approve.
                    </li>
                </Instructions>
            );
        case 'cursor':
            return (
                <Instructions>
                    <li>
                        Open <b>~/.cursor/mcp.json</b> and add:
                        <CodeBlock
                            value={JSON.stringify(
                                { mcpServers: { shoutrrr: { url: endpoint } } },
                                null,
                                2,
                            )}
                        />
                    </li>
                    <li>
                        Open Cursor <b>Settings</b>, then <b>MCP</b>. When
                        Cursor asks, sign in to Shoutrrr and approve.
                    </li>
                </Instructions>
            );
        case 'other':
            return (
                <Instructions>
                    <li>
                        Find where your app adds a <b>custom connector</b> or a{' '}
                        <b>remote MCP server</b>. Paste this URL:{url}
                    </li>
                    <li>When the app asks, sign in to Shoutrrr and approve.</li>
                </Instructions>
            );
    }
}

function Instructions({ children }: { children: ReactNode }) {
    return (
        <ol className="flex list-decimal flex-col gap-3 pl-5 text-sm marker:text-muted-foreground [&_b]:font-medium">
            {children}
        </ol>
    );
}

function PromptRow({ prompt }: { prompt: string }) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === prompt;

    async function onCopy() {
        if (!(await copy(prompt))) {
            toast.error('Could not copy. Select the text and copy it.');
        }
    }

    return (
        <li className="flex items-center justify-between gap-3 rounded-xl bg-muted/50 py-2 pr-2 pl-3 text-sm">
            <span>{prompt}</span>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                className="shrink-0"
                aria-label={copied ? 'Copied' : 'Copy'}
                onClick={onCopy}
            >
                {copied ? <Check /> : <Copy />}
            </Button>
        </li>
    );
}

function CopyField({ value, label }: { value: string; label?: string }) {
    const [copiedText, copy] = useClipboard();

    async function onCopy() {
        if (!(await copy(value))) {
            toast.error('Could not copy. Select the text and copy it.');
        }
    }

    return (
        <div className="mt-2 flex gap-2">
            <Input
                readOnly
                value={value}
                aria-label={label ?? value}
                onFocus={(event) => event.target.select()}
                className="font-mono text-xs"
            />
            <Button
                type="button"
                variant="outline"
                className="shrink-0"
                onClick={onCopy}
            >
                {copiedText === value ? <Check /> : <Copy />}
                {copiedText === value ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

function CodeBlock({ value }: { value: string }) {
    return (
        <pre className="mt-2 overflow-x-auto rounded-xl border bg-muted/50 p-3 font-mono text-xs leading-5">
            {value}
        </pre>
    );
}

AgenticHome.layout = {
    breadcrumbs: [
        {
            title: 'Get started',
            href: agenticHome(),
        },
    ],
};

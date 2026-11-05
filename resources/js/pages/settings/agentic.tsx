import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Check, Copy } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useClipboard } from '@/hooks/use-clipboard';
import { edit as editAgenticSettings } from '@/routes/agentic-settings';

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
    agent_connected: boolean;
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
    'Set up a sync: when I post on X, also post it to Bluesky and LinkedIn.',
    'Post "We just shipped a new release!" to all my accounts.',
    'Schedule a post for tomorrow at 9:00 that says "Good morning!".',
    'What posts do I have scheduled this week?',
];

/** LinkedIn Pages is a connect flow, not a platform, so it uses the LinkedIn mark. */

export default function AgenticSettings({ mcp, api, agent_connected }: Props) {
    const { workspaces } = usePage().props;
    const canManageWorkspace = (workspaces.current?.permissions ?? []).includes(
        'workspace.settings.manage',
    );
    const [client, setClient] = useState<ClientKey>('claude');

    return (
        <>
            <Head title="Agentic" />
            <h1 className="sr-only">Agentic</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="AI assistant (MCP)"
                        description={
                            agent_connected
                                ? 'An assistant is connected to this workspace. To add another app, follow the same steps.'
                                : 'Connect Claude, ChatGPT, or another AI app. Then ask it to write, post, schedule, or set up sync for you. You sign in to Shoutrrr one time to approve it.'
                        }
                    />
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
                    <p className="text-sm text-muted-foreground">
                        Then talk to it like you talk to a person. Before it
                        publishes, it asks you to confirm. Some examples:
                    </p>
                    <ul className="flex flex-col gap-2">
                        {EXAMPLE_PROMPTS.map((prompt) => (
                            <PromptRow key={prompt} prompt={prompt} />
                        ))}
                    </ul>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="REST API"
                        description="Scripts and your own tools can post without an AI assistant. Use a new Idempotency-Key for each post, so a retry does not post twice."
                    />
                    <p className="text-sm text-muted-foreground">
                        Base URL:{' '}
                        <code className="font-mono text-xs">
                            {api.base_url}
                        </code>
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
                </section>
            </div>
        </>
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

AgenticSettings.layout = {
    breadcrumbs: [
        {
            title: 'Agentic',
            href: editAgenticSettings(),
        },
    ],
};

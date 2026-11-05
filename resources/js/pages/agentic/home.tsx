import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';

import SyncPipelinesController from '@/actions/App/Http/Controllers/Settings/SyncPipelinesController';
import { AccountAvatar } from '@/components/common/account-avatar';
import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Check } from '@/components/ui/icons';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import { home as agenticHome } from '@/routes/agentic';
import { edit as editAgenticSettings } from '@/routes/agentic-settings';
import type { PlatformName } from '@/types/compose';

type ConnectablePlatform = {
    platform: string;
    label: string;
    method: string;
    url: string;
};

type HomeAccount = {
    id: string;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
    supports_native: boolean;
};

type SyncSuggestion = {
    name: string;
    source_connected_account_id: string;
    destination_connected_account_ids: string[];
};

type RecentPost = {
    id: string;
    base_text: string;
    origin: string;
    status: string;
    status_label: string;
    platforms: string[];
    updated_at: string;
    scheduled_at: string | null;
    published_at: string | null;
};

type Props = {
    platforms: ConnectablePlatform[];
    accounts: HomeAccount[];
    sync: {
        total: number;
        enabled: number;
        can_create: boolean;
        can_track: boolean;
        suggestion: SyncSuggestion | null;
    };
    recent_posts: RecentPost[] | null;
};

const ORIGIN_LABEL: Record<string, string> = {
    sync: 'Synced',
    agent: 'AI assistant',
    external: 'Posted natively',
    composer: 'Composer',
};

/** LinkedIn Pages is a connect flow, not a platform, so it uses the LinkedIn mark. */
function glyphFor(platform: string): PlatformName {
    return (
        platform === 'linkedin-pages' ? 'linkedin' : platform
    ) as PlatformName;
}

function accountName(account: HomeAccount): string {
    return account.display_name ?? account.handle;
}

export default function AgenticHome({
    platforms,
    accounts,
    sync,
    recent_posts,
}: Props) {
    return (
        <>
            <Head title={recent_posts === null ? 'Get started' : 'Home'} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-10 px-4 pt-10 pb-16">
                {recent_posts === null ? (
                    <Setup
                        platforms={platforms}
                        accounts={accounts}
                        sync={sync}
                    />
                ) : (
                    <RecentPosts posts={recent_posts} sync={sync} />
                )}
            </div>
        </>
    );
}

function Setup({
    platforms,
    accounts,
    sync,
}: Pick<Props, 'platforms' | 'accounts' | 'sync'>) {
    const { workspaces } = usePage().props;
    const canManageWorkspace = (workspaces.current?.permissions ?? []).includes(
        'workspace.settings.manage',
    );
    const syncDone = sync.total > 0;

    return (
        <>
            <header className="flex flex-col gap-2">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Post once, show up everywhere
                </h1>
                <p className="text-sm text-muted-foreground">
                    Connect your social accounts and turn on sync. When you post
                    on one account, Shoutrrr copies the post to your other
                    accounts.
                </p>
            </header>

            <ol className="flex flex-col gap-4">
                <Step
                    number={1}
                    done={accounts.length >= 2}
                    title="Connect your social accounts"
                    description={
                        accounts.length === 0
                            ? 'Connect at least two accounts. Sync copies posts from one account to the others.'
                            : accounts.length === 1
                              ? 'One account is connected. Connect one more to turn on sync.'
                              : `${accounts.length} accounts are connected. You can add more at any time.`
                    }
                >
                    {accounts.length > 0 ? (
                        <ul className="flex flex-wrap gap-2">
                            {accounts.map((account) => (
                                <li
                                    key={account.id}
                                    className="flex items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm"
                                >
                                    <AccountAvatar
                                        platform={account.platform}
                                        handle={account.handle}
                                        avatarUrl={account.avatar_url}
                                        ringClassName="ring-card"
                                    />
                                    <span className="max-w-40 truncate">
                                        {accountName(account)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <PlatformButtons platforms={platforms} />
                </Step>

                <Step
                    number={2}
                    done={syncDone}
                    title="Turn on sync"
                    description={
                        syncDone
                            ? `${sync.enabled} of ${sync.total} sync ${sync.total === 1 ? 'pipeline is' : 'pipelines are'} on. Post on a source account, and your posts show here.`
                            : 'Pick the account you post on most. Shoutrrr copies each new post from it to the accounts you select.'
                    }
                >
                    <SyncStep
                        accounts={accounts}
                        sync={sync}
                        canManage={canManageWorkspace}
                    />
                </Step>
            </ol>

            <p className="text-sm text-muted-foreground">
                Do you want an AI assistant to post for you? Go to{' '}
                <Link
                    href={editAgenticSettings()}
                    className="text-foreground underline-offset-4 hover:underline"
                >
                    Settings → Agentic
                </Link>
                .
            </p>
        </>
    );
}

function RecentPosts({
    posts,
    sync,
}: {
    posts: RecentPost[];
    sync: Props['sync'];
}) {
    return (
        <>
            <header className="flex items-end justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Recent posts
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {sync.total > 0
                            ? `${sync.enabled} of ${sync.total} sync ${sync.total === 1 ? 'pipeline is' : 'pipelines are'} on.`
                            : 'No sync is on.'}
                    </p>
                </div>
                <Link
                    href={SyncPipelinesController.index()}
                    className="shrink-0 text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    Manage syncs
                </Link>
            </header>

            {posts.length === 0 ? (
                <p className="text-sm text-muted-foreground">No posts yet.</p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {posts.map((post) => (
                        <li
                            key={post.id}
                            className="flex flex-col gap-2 rounded-2xl border bg-card p-4"
                        >
                            <p className="line-clamp-3 text-sm whitespace-pre-line">
                                {post.base_text}
                            </p>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    {post.platforms.map((platform) => (
                                        <PlatformGlyph
                                            key={platform}
                                            platform={glyphFor(platform)}
                                            size={12}
                                        />
                                    ))}
                                </span>
                                <span>
                                    {ORIGIN_LABEL[post.origin] ?? post.origin}
                                </span>
                                <span
                                    className={cn(
                                        (post.status === 'failed' ||
                                            post.status === 'partial') &&
                                            'text-destructive',
                                    )}
                                >
                                    {post.status_label}
                                </span>
                                <span className="ml-auto">
                                    {dayjs(
                                        post.published_at ??
                                            post.scheduled_at ??
                                            post.updated_at,
                                    ).fromNow()}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

function Step({
    number,
    done,
    optional = false,
    title,
    description,
    children,
}: {
    number: number;
    done: boolean;
    optional?: boolean;
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
                    <h2 className="font-medium">
                        {title}
                        {optional ? (
                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                Optional
                            </span>
                        ) : null}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {children}
            </div>
        </li>
    );
}

function PlatformButtons({ platforms }: { platforms: ConnectablePlatform[] }) {
    if (platforms.length === 0) {
        return (
            <Button
                nativeButton={false}
                variant="outline"
                className="self-start"
                render={<Link href={accountsRoute()} />}
            >
                Manage accounts
            </Button>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {platforms.map((platform) => {
                    const content = (
                        <>
                            <PlatformGlyph
                                platform={glyphFor(platform.platform)}
                                size={14}
                            />
                            <span className="truncate">{platform.label}</span>
                        </>
                    );

                    // OAuth leaves the app, so it needs a full page load.
                    // Webhooks and app passwords are pasted on the Accounts page.
                    return platform.method === 'oauth' ? (
                        <Button
                            key={platform.platform}
                            nativeButton={false}
                            variant="outline"
                            className="justify-start"
                            render={<a href={platform.url} />}
                        >
                            {content}
                        </Button>
                    ) : (
                        <Button
                            key={platform.platform}
                            nativeButton={false}
                            variant="outline"
                            className="justify-start"
                            render={<Link href={platform.url} />}
                        >
                            {content}
                        </Button>
                    );
                })}
            </div>
            <Link
                href={accountsRoute()}
                className="self-start text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                Manage accounts
            </Link>
        </div>
    );
}

function SyncStep({
    accounts,
    sync,
    canManage,
}: {
    accounts: HomeAccount[];
    sync: Props['sync'];
    canManage: boolean;
}) {
    if (sync.total > 0) {
        return canManage ? (
            <Button
                nativeButton={false}
                variant="outline"
                className="self-start"
                render={<Link href={SyncPipelinesController.index()} />}
            >
                Manage syncs
            </Button>
        ) : null;
    }

    if (!canManage) {
        return (
            <p className="text-sm text-muted-foreground">
                Ask a workspace admin to turn on sync.
            </p>
        );
    }

    if (sync.suggestion === null) {
        return (
            <p className="text-sm text-muted-foreground">
                Connect at least two accounts first.
            </p>
        );
    }

    if (!sync.can_create) {
        return (
            <p className="text-sm text-muted-foreground">
                Your plan does not allow more sync pipelines.
            </p>
        );
    }

    return (
        <SyncSuggestionForm
            accounts={accounts}
            suggestion={sync.suggestion}
            canTrack={sync.can_track}
        />
    );
}

function SyncSuggestionForm({
    accounts,
    suggestion,
    canTrack,
}: {
    accounts: HomeAccount[];
    suggestion: SyncSuggestion;
    canTrack: boolean;
}) {
    const [source, setSource] = useState(
        suggestion.source_connected_account_id,
    );
    const [destinations, setDestinations] = useState<string[]>(
        suggestion.destination_connected_account_ids,
    );
    const [processing, setProcessing] = useState(false);
    const sourceAccount = accounts.find((account) => account.id === source);
    const tooMany = destinations.length > 3;

    function selectSource(id: string) {
        setSource(id);
        setDestinations((current) => {
            const rest = current.filter((d) => d !== id);
            // Keep the previous source as a destination, so a swap is one click.
            return rest.length < 3 && source !== id ? [...rest, source] : rest;
        });
    }

    function toggleDestination(id: string) {
        setDestinations((current) =>
            current.includes(id)
                ? current.filter((d) => d !== id)
                : [...current, id],
        );
    }

    function submit() {
        if (sourceAccount === undefined) {
            return;
        }

        router.post(
            SyncPipelinesController.store().url,
            {
                name: `${accountName(sourceAccount)} sync`,
                source_connected_account_id: source,
                destination_connected_account_ids: destinations,
                track_source: true,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => {
                    toast.error(
                        Object.values(errors)[0] ??
                            'Could not turn on sync. Try again.',
                    );
                },
            },
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-2">
                <Label>Post on</Label>
                <RadioGroup
                    value={source}
                    onValueChange={(value) => selectSource(value as string)}
                >
                    {accounts.map((account) => (
                        <div
                            key={account.id}
                            className="flex items-center gap-3 text-sm"
                        >
                            <RadioGroupItem
                                id={`sync-source-${account.id}`}
                                value={account.id}
                            />
                            <Label
                                htmlFor={`sync-source-${account.id}`}
                                className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 font-normal"
                            >
                                <AccountAvatar
                                    platform={account.platform}
                                    handle={account.handle}
                                    avatarUrl={account.avatar_url}
                                    ringClassName="ring-card"
                                />
                                <span className="truncate">
                                    {accountName(account)}
                                </span>
                            </Label>
                        </div>
                    ))}
                </RadioGroup>
            </div>

            <div className="grid gap-2">
                <Label>Copy to (up to 3)</Label>
                {accounts
                    .filter((account) => account.id !== source)
                    .map((account) => (
                        <div
                            key={account.id}
                            className="flex items-center gap-3 text-sm"
                        >
                            <Checkbox
                                id={`sync-dest-${account.id}`}
                                checked={destinations.includes(account.id)}
                                onCheckedChange={() =>
                                    toggleDestination(account.id)
                                }
                            />
                            <Label
                                htmlFor={`sync-dest-${account.id}`}
                                className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 font-normal"
                            >
                                <AccountAvatar
                                    platform={account.platform}
                                    handle={account.handle}
                                    avatarUrl={account.avatar_url}
                                    ringClassName="ring-card"
                                />
                                <span className="truncate">
                                    {accountName(account)}
                                </span>
                            </Label>
                        </div>
                    ))}
                {tooMany ? (
                    <p className="text-sm text-destructive">
                        Select 3 accounts or fewer.
                    </p>
                ) : null}
            </div>

            {sourceAccount ? (
                <p className="text-sm text-muted-foreground">
                    {!sourceAccount.supports_native
                        ? 'This account cannot be tracked. Only posts that you publish through Shoutrrr are copied.'
                        : canTrack
                          ? 'Posts that you make directly on this account are copied too.'
                          : 'Your plan cannot track more accounts. Only posts that you publish through Shoutrrr are copied.'}
                </p>
            ) : null}

            <Button
                type="button"
                className="self-start"
                disabled={processing || destinations.length === 0 || tooMany}
                onClick={submit}
            >
                {processing ? 'Turning on…' : 'Turn on sync'}
            </Button>
        </div>
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

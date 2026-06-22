import { router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    FileText,
    ListChecks,
    LogOut,
    Moon,
    Pencil,
    Plug,
    Settings,
    Share2,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import {
    Command,
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { useAppearance } from '@/hooks/use-appearance';
import { parseDateJump } from '@/lib/command/parse-date-jump';
import {
    readRecents,
    recordRecent,
    type RecentItem,
} from '@/lib/command/recents';
import { usePostSearch } from '@/lib/command/use-post-search';
import { switchWorkspace } from '@/lib/workspaces/switch-workspace';
import { dashboard, logout } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import {
    index as calendarRoute,
    month as calendarMonth,
} from '@/routes/calendar';
import { index as postsRoute } from '@/routes/posts';

import { ComposeDestinationPage } from './command-palette/compose-destination-page';
import { ConnectPlatformPage } from './command-palette/connect-platform-page';
import { PostsGroup } from './command-palette/posts-group';
import { ThemePage } from './command-palette/theme-page';

/** Window event the topbar search button dispatches to open the palette. */
export const OPEN_COMMAND_EVENT = 'shoutrrr:open-command';

export function openCommandPalette() {
    window.dispatchEvent(new Event(OPEN_COMMAND_EVENT));
}

export function CommandPalette() {
    const { workspaces, shell } = usePage().props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [recents, setRecents] = useState<RecentItem[]>([]);
    const [page, setPage] = useState<
        null | 'compose-destination' | 'connect-platform' | 'theme'
    >(null);
    const { updateAppearance } = useAppearance();

    // Refresh the recents list each time the palette opens.
    useEffect(() => {
        if (open) {
            setRecents(readRecents());
        }
    }, [open]);

    const composeUrl = dashboard().url;
    const { posts, loading, error } = usePostSearch(query);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            const meta = e.metaKey || e.ctrlKey;
            if (!meta) {
                return;
            }
            const key = e.key.toLowerCase();
            if (key === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
            } else if (key === 'n') {
                e.preventDefault();
                router.visit(composeUrl);
            }
        }
        function onOpen() {
            setOpen(true);
        }
        window.addEventListener('keydown', onKey);
        window.addEventListener(OPEN_COMMAND_EVENT, onOpen);

        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener(OPEN_COMMAND_EVENT, onOpen);
        };
    }, [composeUrl]);

    // Reset the query and page each time the dialog closes so it reopens clean.
    function onOpenChange(next: boolean) {
        setOpen(next);
        if (!next) {
            setQuery('');
            setPage(null);
        }
    }

    function run(fn: () => void) {
        return () => {
            onOpenChange(false);
            fn();
        };
    }

    function go(item: RecentItem) {
        return run(() => {
            recordRecent(item);
            router.visit(item.href);
        });
    }

    const current = workspaces.current;
    const showSettings = workspaces.enabled && current;
    const otherWorkspaces = workspaces.enabled
        ? workspaces.all.filter((w) => w.id !== current?.id)
        : [];

    const trimmed = query.trim();
    const dateJump =
        trimmed.length > 0 ? parseDateJump(trimmed, new Date()) : null;
    const matchedAccounts =
        trimmed.length > 0
            ? shell.accounts.filter((a) =>
                  `${a.handle} ${a.display_name ?? ''} ${a.platform}`
                      .toLowerCase()
                      .includes(trimmed.toLowerCase()),
              )
            : [];

    return (
        <CommandDialog open={open} onOpenChange={onOpenChange}>
            <Command
                shouldFilter={false}
                onKeyDown={(e) => {
                    if (
                        e.key === 'Backspace' &&
                        query.length === 0 &&
                        page !== null
                    ) {
                        e.preventDefault();
                        setPage(null);
                    }
                }}
            >
                <CommandInput
                    placeholder="Type a command or search…"
                    value={query}
                    onValueChange={setQuery}
                />
                <CommandList>
                    <CommandEmpty>No results.</CommandEmpty>

                    {page === 'compose-destination' && (
                        <ComposeDestinationPage
                            accounts={shell.accounts}
                            sets={shell.sets}
                            composeUrl={composeUrl}
                            run={run}
                        />
                    )}

                    {page === 'connect-platform' && (
                        <ConnectPlatformPage run={run} />
                    )}

                    {page === 'theme' && (
                        <ThemePage
                            run={run}
                            updateAppearance={updateAppearance}
                        />
                    )}

                    {page === null && (
                        <>
                            {trimmed.length === 0 && recents.length > 0 && (
                                <>
                                    <CommandGroup heading="Recent">
                                        {recents.map((item) => (
                                            <CommandItem
                                                key={item.id}
                                                value={`recent ${item.id} ${item.label}`}
                                                onSelect={go(item)}
                                            >
                                                <FileText
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                <span className="truncate">
                                                    {item.label}
                                                </span>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                    <CommandSeparator alwaysRender />
                                </>
                            )}

                            <CommandGroup heading="Go to">
                                <CommandItem
                                    value="compose new post"
                                    onSelect={run(() =>
                                        router.visit(composeUrl),
                                    )}
                                >
                                    <Pencil className="size-4" aria-hidden />
                                    Compose
                                </CommandItem>
                                <CommandItem
                                    value="new post for channel compose target"
                                    onSelect={() => {
                                        setQuery('');
                                        setPage('compose-destination');
                                    }}
                                >
                                    <Pencil className="size-4" aria-hidden />
                                    New post for…
                                </CommandItem>
                                <CommandItem
                                    value="posts"
                                    onSelect={run(() =>
                                        router.visit(postsRoute().url),
                                    )}
                                >
                                    <FileText className="size-4" aria-hidden />
                                    Posts
                                </CommandItem>
                                <CommandItem
                                    value="calendar"
                                    onSelect={run(() =>
                                        router.visit(calendarRoute().url),
                                    )}
                                >
                                    <CalendarDays
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Calendar
                                </CommandItem>
                                <CommandItem
                                    value="queue"
                                    onSelect={run(() =>
                                        router.visit(
                                            PostingScheduleController.show()
                                                .url,
                                        ),
                                    )}
                                >
                                    <ListChecks
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Queue
                                </CommandItem>
                                <CommandItem
                                    value="accounts connections"
                                    onSelect={run(() =>
                                        router.visit(accountsRoute().url),
                                    )}
                                >
                                    <Share2 className="size-4" aria-hidden />
                                    Accounts
                                </CommandItem>
                                {showSettings && (
                                    <CommandItem
                                        value="workspace settings"
                                        onSelect={run(() =>
                                            router.visit(
                                                WorkspaceSettingsController.showOverview()
                                                    .url,
                                            ),
                                        )}
                                    >
                                        <Settings
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Workspace settings
                                    </CommandItem>
                                )}
                            </CommandGroup>

                            {dateJump && (
                                <>
                                    <CommandSeparator alwaysRender />
                                    <CommandGroup heading="Jump to date">
                                        <CommandItem
                                            value={`calendar ${dateJump.yyyymm}`}
                                            onSelect={run(() =>
                                                router.visit(
                                                    calendarMonth({
                                                        yyyymm: dateJump.yyyymm,
                                                    }).url,
                                                ),
                                            )}
                                        >
                                            <CalendarDays
                                                className="size-4"
                                                aria-hidden
                                            />
                                            {dateJump.label}
                                        </CommandItem>
                                    </CommandGroup>
                                </>
                            )}

                            {trimmed.length >= 2 && (
                                <PostsGroup
                                    posts={posts}
                                    loading={loading}
                                    error={error}
                                    go={go}
                                />
                            )}

                            {matchedAccounts.length > 0 && (
                                <>
                                    <CommandSeparator alwaysRender />
                                    <CommandGroup heading="Accounts">
                                        {matchedAccounts.map((account) => (
                                            <CommandItem
                                                key={account.id}
                                                value={`account ${account.handle}`}
                                                onSelect={run(() =>
                                                    router.visit(
                                                        accountsRoute().url,
                                                    ),
                                                )}
                                            >
                                                <Share2
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                <span className="truncate">
                                                    {account.handle}
                                                </span>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                </>
                            )}

                            {otherWorkspaces.length > 0 && (
                                <>
                                    <CommandSeparator alwaysRender />
                                    <CommandGroup heading="Switch workspace">
                                        {otherWorkspaces.map((workspace) => (
                                            <CommandItem
                                                key={workspace.id}
                                                value={`switch workspace ${workspace.name}`}
                                                onSelect={run(() =>
                                                    switchWorkspace(
                                                        workspace.id,
                                                    ),
                                                )}
                                            >
                                                <Building2
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                <span className="truncate">
                                                    {workspace.name}
                                                </span>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                </>
                            )}

                            <CommandSeparator alwaysRender />
                            <CommandGroup heading="Account">
                                <CommandItem
                                    value="connect account add channel"
                                    onSelect={() => {
                                        setQuery('');
                                        setPage('connect-platform');
                                    }}
                                >
                                    <Plug className="size-4" aria-hidden />
                                    Connect account
                                </CommandItem>
                                <CommandItem
                                    value="switch theme appearance dark light"
                                    onSelect={() => {
                                        setQuery('');
                                        setPage('theme');
                                    }}
                                >
                                    <Moon className="size-4" aria-hidden />
                                    Switch theme
                                </CommandItem>
                                <CommandItem
                                    value="sign out log out"
                                    onSelect={run(() =>
                                        router.post(logout.url()),
                                    )}
                                >
                                    <LogOut className="size-4" aria-hidden />
                                    Sign out
                                </CommandItem>
                            </CommandGroup>
                        </>
                    )}
                </CommandList>
            </Command>
        </CommandDialog>
    );
}

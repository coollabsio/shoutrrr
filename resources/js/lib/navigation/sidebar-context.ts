export type SidebarContextId =
    | 'account'
    | 'workspace'
    | 'instance'
    | 'post'
    | 'accounts';

export type SidebarContext = {
    id: SidebarContextId;
    label: string;
    backTitle: string;
    postId: string | null;
};

const BACK_TO_MAIN = 'Back to the main menu';

const LABELS: Record<SidebarContextId, string> = {
    account: 'Settings',
    workspace: 'Workspace',
    instance: 'Instance settings',
    post: 'Post',
    accounts: 'Accounts',
};

function pathnameOf(url: string): string {
    const path = url.split('?')[0].split('#')[0];

    if (path.length > 1 && path.endsWith('/')) {
        return path.slice(0, -1);
    }

    return path || '/';
}

function matchesPrefix(path: string, prefix: string): boolean {
    return path === prefix || path.startsWith(`${prefix}/`);
}

const ACCOUNT_PREFIXES = [
    '/settings/profile',
    '/settings/security',
    '/settings/appearance',
    '/settings/notifications',
    '/settings/connections',
];

/**
 * The menu that replaces the main sidebar on the current URL.
 * Settings families and a single post or account wizard swap in.
 * Top-level lists (home, posts, calendar, accounts index) stay on the main menu.
 * A post is Classic-only so Agentic never picks up the composer.
 */
export function sidebarContextFor(
    url: string,
    { classicMode }: { classicMode: boolean },
): SidebarContext | null {
    const path = pathnameOf(url);
    let id: SidebarContextId | null = null;
    let postId: string | null = null;

    if (matchesPrefix(path, '/settings/workspace')) {
        id = 'workspace';
    } else if (matchesPrefix(path, '/settings/instance')) {
        id = 'instance';
    } else if (ACCOUNT_PREFIXES.some((prefix) => matchesPrefix(path, prefix))) {
        id = 'account';
    } else if (classicMode) {
        const post = /^\/posts\/(?!next-slot$)([^/]+)$/.exec(path);
        if (post) {
            id = 'post';
            postId = post[1];
        }
    }

    if (id === null && path.startsWith('/accounts/')) {
        id = 'accounts';
    }

    if (id === null) {
        return null;
    }

    return {
        id,
        label: LABELS[id],
        backTitle: BACK_TO_MAIN,
        postId,
    };
}

export function sidebarContextLabel(id: SidebarContextId): string {
    return LABELS[id];
}

import {
    type Account,
    BASE_TAB,
    type Destination,
    type MediaView,
    type MentionPlaceholder,
    type PostFormat,
    type PostView,
} from '@/types/compose';

export type SaveState =
    | 'idle'
    | 'dirty'
    | 'saving'
    | 'saved'
    | 'offline'
    | 'conflict';

export type ScheduleMode = 'now' | 'queue' | 'pick';

export type ScheduleTray = {
    mode: ScheduleMode;
    pickedAt: string | null;
};

export type ComposerState = {
    postId: string | null;
    activeTab: string;
    saveState: SaveState;
    baselineUpdatedAt: string | null;
    segments: string[];
    mentions: MentionPlaceholder[];
    destination: Destination;
    autoSplitByAccount: Record<string, boolean>;
    formatByAccount: Record<string, PostFormat>;
    overrideByAccount: Record<string, string[] | undefined>;
    mediaSubsetExcludes: Set<string>;
    media: MediaView[];
    scheduleTray: ScheduleTray;
    conflict: PostView | null;
};

export type ComposerAction =
    | { type: 'hydrate'; post: PostView }
    | { type: 'syncServerPost'; post: PostView }
    | { type: 'setPostId'; postId: string; updatedAt: string }
    | { type: 'updateSegments'; segments: string[] }
    | { type: 'setMentions'; mentions: MentionPlaceholder[] }
    | { type: 'setActiveTab'; tab: string }
    | { type: 'setDestination'; destination: Destination }
    | { type: 'toggleAutoSplit'; accountId: string }
    | { type: 'setFormat'; accountId: string; format: PostFormat }
    | { type: 'disableAutoSplit'; accountIds: string[] }
    | { type: 'setOverrideSegments'; accountId: string; segments: string[] }
    | { type: 'discardOverride'; accountId: string }
    | { type: 'toggleMediaExclude'; mediaId: string; accountId: string }
    | { type: 'addMedia'; media: MediaView }
    | { type: 'replaceMedia'; media: MediaView }
    | { type: 'removeMedia'; mediaId: string }
    | { type: 'reorderMedia'; ids: string[] }
    | { type: 'setScheduleTray'; tray: ScheduleTray }
    | { type: 'saveStarted' }
    | { type: 'saveSkippedEmpty' }
    | { type: 'saveSucceeded'; post: PostView }
    | { type: 'saveFailedOffline' }
    | { type: 'saveFailedStale'; post: PostView }
    | { type: 'resolveConflictUseServer' }
    | { type: 'resolveConflictKeepMine' };

/**
 * Resolve which account the editor surfaces. `activeTab` is seeded from the
 * post's first target (or BASE_TAB for a target-less draft), so when a draft has
 * no targets yet we fall back to the first available account — otherwise a
 * connected account would still show the "connect an account" nudge.
 */
export function pickActiveAccount(
    tabAccounts: Account[],
    activeTab: string,
): Account | null {
    return (
        tabAccounts.find((a) => a.id === activeTab) ?? tabAccounts[0] ?? null
    );
}

/**
 * The connect-account nudge is a workspace-level empty state. Do not show it
 * just because the current destination resolves to no active tab/accounts.
 */
export function shouldShowConnectAccountPrompt(
    accounts: Account[],
    activeAccount: Account | null,
): boolean {
    return accounts.length === 0 && activeAccount === null;
}

/**
 * Build a fresh composer state. When `scheduleAt` (an ISO string) is given, the
 * schedule tray opens pre-set to "Pick time" at that instant — used when the
 * composer is opened from a calendar slot click. When `initialDestination` is
 * given, the destination selector is pre-seeded (e.g. from a ?destination= query
 * param set by the command palette's "compose for channel" action).
 */
export function initialComposerState(
    scheduleAt: string | null = null,
    initialDestination: Destination | null = null,
): ComposerState {
    return {
        postId: null,
        activeTab: BASE_TAB,
        saveState: 'idle',
        baselineUpdatedAt: null,
        segments: [''],
        mentions: [],
        destination: initialDestination ?? { kind: 'all' },
        autoSplitByAccount: {},
        formatByAccount: {},
        overrideByAccount: {},
        mediaSubsetExcludes: new Set(),
        media: [],
        scheduleTray: scheduleAt
            ? { mode: 'pick', pickedAt: scheduleAt }
            : { mode: 'now', pickedAt: null },
        conflict: null,
    };
}

/**
 * Parse the `?destination=` query-param value produced by the command palette's
 * "compose for channel" action. Returns null for any unrecognised input so the
 * composer falls back to its default `{ kind: 'all' }` destination.
 */
export function parseDestinationParam(raw: string | null): Destination | null {
    if (!raw) {
        return null;
    }
    if (raw === 'all') {
        return { kind: 'all' };
    }
    const [kind, id] = raw.split(':');
    if ((kind === 'account' || kind === 'set') && id) {
        return { kind, id };
    }
    if (kind === 'accounts' && id) {
        const ids = id.split(',').filter(Boolean);

        return ids.length > 0 ? { kind: 'accounts', ids } : null;
    }

    return null;
}

function hydrate(post: PostView): ComposerState {
    const autoSplitByAccount: Record<string, boolean> = {};
    const formatByAccount: Record<string, PostFormat> = {};
    const overrideByAccount: Record<string, string[] | undefined> = {};
    const mediaSubsetExcludes = new Set<string>();

    for (const target of post.targets) {
        autoSplitByAccount[target.connected_account_id] = target.auto_split;
        formatByAccount[target.connected_account_id] = target.format;
        const overrideSegments = target.content_override?.segments;
        if (overrideSegments !== undefined && overrideSegments !== null) {
            overrideByAccount[target.connected_account_id] = overrideSegments;
        }
    }

    return {
        postId: post.id,
        activeTab: post.targets[0]?.connected_account_id ?? BASE_TAB,
        saveState: 'saved',
        baselineUpdatedAt: post.updated_at,
        segments: post.segments,
        mentions: post.mentions ?? [],
        destination:
            post.destination.kind === 'set' && post.destination.id
                ? { kind: 'set', id: post.destination.id }
                : post.destination.kind === 'account' && post.destination.id
                  ? { kind: 'account', id: post.destination.id }
                  : post.destination.kind === 'accounts' &&
                      post.destination.ids &&
                      post.destination.ids.length > 0
                    ? { kind: 'accounts', ids: post.destination.ids }
                    : { kind: 'all' },
        autoSplitByAccount,
        formatByAccount,
        overrideByAccount,
        mediaSubsetExcludes,
        media: post.media,
        scheduleTray: {
            mode: post.scheduled_at ? 'pick' : 'now',
            pickedAt: post.scheduled_at ?? null,
        },
        conflict: null,
    };
}

export function composerReducer(
    state: ComposerState,
    action: ComposerAction,
): ComposerState {
    switch (action.type) {
        case 'hydrate':
            return hydrate(action.post);

        case 'syncServerPost': {
            // The reducer seeds from `post` only at mount, but a server-driven
            // navigation/reload can deliver a newer version of THIS post — e.g.
            // a schedule / queue / publish mutation bumps `updated_at` via its
            // own request, outside the autosave path. Without re-syncing, the
            // composer keeps the pre-mutation baseline and the next autosave
            // 409s against the user's own change ("someone else updated this
            // post"). Adopt the server version, but never clobber local edits or
            // an open conflict — those own the state.
            if (action.post.id !== state.postId) {
                return hydrate(action.post);
            }
            if (
                state.saveState === 'dirty' ||
                state.saveState === 'saving' ||
                state.saveState === 'conflict'
            ) {
                return state;
            }
            if (action.post.updated_at === state.baselineUpdatedAt) {
                return state;
            }

            return hydrate(action.post);
        }

        case 'setPostId':
            return {
                ...state,
                postId: action.postId,
                baselineUpdatedAt: action.updatedAt,
                saveState: 'saved',
            };

        case 'updateSegments':
            if (state.saveState === 'conflict') {
                return state;
            }
            if (
                JSON.stringify(state.segments) ===
                JSON.stringify(action.segments)
            ) {
                return state;
            }

            return { ...state, segments: action.segments, saveState: 'dirty' };

        case 'setMentions':
            if (state.saveState === 'conflict') {
                return state;
            }
            if (
                JSON.stringify(state.mentions) ===
                JSON.stringify(action.mentions)
            ) {
                return state;
            }

            return { ...state, mentions: action.mentions, saveState: 'dirty' };

        case 'setActiveTab':
            return { ...state, activeTab: action.tab };

        case 'setDestination':
            return {
                ...state,
                destination: action.destination,
                saveState: 'dirty',
            };

        case 'toggleAutoSplit':
            return {
                ...state,
                autoSplitByAccount: {
                    ...state.autoSplitByAccount,
                    [action.accountId]: !(
                        state.autoSplitByAccount[action.accountId] ?? true
                    ),
                },
                saveState: 'dirty',
            };

        case 'setFormat':
            return {
                ...state,
                formatByAccount: {
                    ...state.formatByAccount,
                    [action.accountId]: action.format,
                },
                saveState: 'dirty',
            };

        case 'disableAutoSplit':
            return {
                ...state,
                autoSplitByAccount: {
                    ...state.autoSplitByAccount,
                    ...Object.fromEntries(
                        action.accountIds.map((accountId) => [
                            accountId,
                            false,
                        ]),
                    ),
                },
                saveState: 'dirty',
            };

        case 'setOverrideSegments':
            return {
                ...state,
                overrideByAccount: {
                    ...state.overrideByAccount,
                    [action.accountId]: action.segments,
                },
                saveState: 'dirty',
            };

        case 'discardOverride': {
            const next = { ...state.overrideByAccount };
            delete next[action.accountId];

            return { ...state, overrideByAccount: next, saveState: 'dirty' };
        }

        case 'toggleMediaExclude': {
            const key = `${action.mediaId}:${action.accountId}`;
            const next = new Set(state.mediaSubsetExcludes);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return { ...state, mediaSubsetExcludes: next, saveState: 'dirty' };
        }

        case 'addMedia':
            return {
                ...state,
                media: [...state.media, action.media],
                saveState: 'dirty',
            };

        case 'replaceMedia':
            return {
                ...state,
                media: state.media.map((m) =>
                    m.id === action.media.id ? action.media : m,
                ),
                saveState: 'dirty',
            };

        case 'removeMedia':
            return {
                ...state,
                media: state.media.filter((m) => m.id !== action.mediaId),
                saveState: 'dirty',
            };

        case 'reorderMedia': {
            // Reorder media to match the given id sequence. Ignore unknown ids
            // and append any media missing from the sequence so a stale ordering
            // never drops attachments.
            const byId = new Map(state.media.map((m) => [m.id, m]));
            const ordered: MediaView[] = [];
            for (const id of action.ids) {
                const found = byId.get(id);
                if (found) {
                    ordered.push(found);
                    byId.delete(id);
                }
            }
            for (const remaining of byId.values()) {
                ordered.push(remaining);
            }

            return { ...state, media: ordered, saveState: 'dirty' };
        }

        case 'setScheduleTray':
            // Scheduling is a separate action from the autosave dirty flow, so
            // this deliberately does not touch saveState.
            return { ...state, scheduleTray: action.tray };

        case 'saveStarted':
            return { ...state, saveState: 'saving' };

        case 'saveSkippedEmpty':
            // The composer is empty and has no persisted draft yet, so a
            // destination change alone must not spawn a blank draft. Drop the
            // dirty flag; typing or attaching media will mark it dirty again.
            return state.saveState === 'dirty'
                ? { ...state, saveState: 'idle' }
                : state;

        case 'saveSucceeded':
            // Preserve 'dirty' if the user typed during the in-flight save so the
            // debounce reschedules; otherwise mark 'saved'. Always advance the
            // baseline and clear any conflict.
            return {
                ...state,
                saveState: state.saveState === 'dirty' ? 'dirty' : 'saved',
                baselineUpdatedAt: action.post.updated_at,
                conflict: null,
            };

        case 'saveFailedOffline':
            return { ...state, saveState: 'offline' };

        case 'saveFailedStale':
            // A stale-write 409 whose server content is byte-identical to the
            // user's is a FALSE conflict: the post's updated_at advanced
            // out-of-band (a schedule/publish/retry, a concurrent autosave, or a
            // baseline that drifted across navigation) but nothing the user can
            // see actually diverged. Silently adopt the server baseline instead
            // of surfacing a no-op "your version / their version" diff. A genuine
            // divergence still opens the conflict dialog.
            if (contentMatchesServer(state, action.post)) {
                return {
                    ...state,
                    saveState: 'saved',
                    baselineUpdatedAt: action.post.updated_at,
                    conflict: null,
                };
            }

            return { ...state, saveState: 'conflict', conflict: action.post };

        case 'resolveConflictUseServer':
            return state.conflict ? hydrate(state.conflict) : state;

        case 'resolveConflictKeepMine':
            return state.conflict
                ? {
                      ...state,
                      baselineUpdatedAt: state.conflict.updated_at,
                      conflict: null,
                      saveState: 'dirty',
                  }
                : state;

        default:
            return state;
    }
}

export type PutTarget = {
    connected_account_id: string;
    auto_split: boolean;
    format: PostFormat;
    content_override: { segments: string[]; media_ids: string[] } | null;
};

export type PutBody = {
    segments: string[];
    destination: Destination;
    targets: PutTarget[];
    media_ids: string[];
    mentions: MentionPlaceholder[];
    expected_updated_at: string | null;
};

/**
 * Build the autosave PUT payload. Each target ALWAYS carries an explicit
 * content_override: the override shape when the account has a local override,
 * or `null` to explicitly clear any stored override server-side (a discard must
 * survive reload; omitting the key would let the old override silently persist).
 */
export function buildPutBody(
    state: ComposerState,
    accountIds: string[],
): PutBody {
    const targets: PutTarget[] = accountIds.map((accountId) => {
        const override = state.overrideByAccount[accountId];
        const content_override =
            override !== undefined
                ? {
                      segments: override,
                      media_ids: state.media
                          .map((m) => m.id)
                          .filter(
                              (mediaId) =>
                                  !state.mediaSubsetExcludes.has(
                                      `${mediaId}:${accountId}`,
                                  ),
                          ),
                  }
                : null;

        return {
            connected_account_id: accountId,
            auto_split: state.autoSplitByAccount[accountId] ?? true,
            format: state.formatByAccount[accountId] ?? 'feed',
            content_override,
        };
    });

    return {
        segments: state.segments,
        destination: state.destination,
        targets,
        media_ids: state.media.map((m) => m.id),
        mentions: state.mentions,
        expected_updated_at: state.baselineUpdatedAt,
    };
}

/**
 * Whether the composer's editable content is byte-identical to a server post —
 * segments, the attached media set, and per-account override segments. Used to
 * distinguish a real edit conflict (content diverged) from a false one (only the
 * post's `updated_at` moved, e.g. a schedule/publish or a drifted baseline).
 */
export function contentMatchesServer(
    state: ComposerState,
    post: PostView,
): boolean {
    if (JSON.stringify(state.segments) !== JSON.stringify(post.segments)) {
        return false;
    }

    if (
        JSON.stringify(state.mentions) !== JSON.stringify(post.mentions ?? [])
    ) {
        return false;
    }

    const localMedia = state.media.map((m) => m.id).sort();
    const serverMedia = post.media.map((m) => m.id).sort();
    if (
        localMedia.length !== serverMedia.length ||
        localMedia.some((id, i) => id !== serverMedia[i])
    ) {
        return false;
    }

    const localOverrides = normalizeOverrides(state.overrideByAccount);
    const serverOverrides: Record<string, string> = {};
    for (const target of post.targets) {
        const segments = target.content_override?.segments;
        if (segments !== undefined && segments !== null) {
            serverOverrides[target.connected_account_id] =
                JSON.stringify(segments);
        }
    }

    const localKeys = Object.keys(localOverrides);
    const serverKeys = Object.keys(serverOverrides);

    return (
        localKeys.length === serverKeys.length &&
        localKeys.every((key) => localOverrides[key] === serverOverrides[key])
    );
}

/**
 * Drop unset entries (undefined/null) from an override map so two maps compare
 * equal when they carry the same *defined* per-account overrides.
 */
function normalizeOverrides(
    overrides: Record<string, string[] | undefined>,
): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [accountId, segments] of Object.entries(overrides)) {
        if (segments !== undefined && segments !== null) {
            out[accountId] = JSON.stringify(segments);
        }
    }

    return out;
}

/**
 * Whether the composer holds anything worth persisting as a draft: segments,
 * any per-account override segments, or attached media. Destination and schedule
 * changes are deliberately NOT content — they must not spawn a blank draft.
 */
export function composerHasContent(state: ComposerState): boolean {
    if (
        state.segments.join('').trim().length > 0 ||
        state.media.length > 0 ||
        state.mentions.length > 0
    ) {
        return true;
    }

    return Object.values(state.overrideByAccount).some(
        (segments) => (segments ?? []).join('').trim().length > 0,
    );
}

/**
 * Derive a draft title from segments: the first non-empty line across all
 * segments, trimmed, and truncated to 80 characters with an ellipsis. Returns
 * '' when the segments have no non-empty line.
 */
export function firstLineTitle(segments: string[]): string {
    const trimmed = (
        segments
            .join('\n')
            .split('\n')
            .find((line) => line.trim().length > 0) ?? ''
    ).trim();

    return trimmed.length > 80 ? `${trimmed.slice(0, 80)}…` : trimmed;
}

import {
    BASE_TAB,
    type Destination,
    type MediaView,
    type PostView,
} from './types';

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
    baseText: string;
    destination: Destination;
    autoSplitByAccount: Record<string, boolean>;
    overrideByAccount: Record<string, string | undefined>;
    mediaSubsetExcludes: Set<string>;
    media: MediaView[];
    scheduleTray: ScheduleTray;
    conflict: PostView | null;
};

export type ComposerAction =
    | { type: 'hydrate'; post: PostView }
    | { type: 'setPostId'; postId: string; updatedAt: string }
    | { type: 'updateBaseText'; text: string }
    | { type: 'setActiveTab'; tab: string }
    | { type: 'setDestination'; destination: Destination }
    | { type: 'toggleAutoSplit'; accountId: string }
    | { type: 'setOverrideText'; accountId: string; text: string }
    | { type: 'discardOverride'; accountId: string }
    | { type: 'toggleMediaExclude'; mediaId: string; accountId: string }
    | { type: 'addMedia'; media: MediaView }
    | { type: 'removeMedia'; mediaId: string }
    | { type: 'reorderMedia'; ids: string[] }
    | { type: 'setScheduleTray'; tray: ScheduleTray }
    | { type: 'saveStarted' }
    | { type: 'saveSucceeded'; post: PostView }
    | { type: 'saveFailedOffline' }
    | { type: 'saveFailedStale'; post: PostView }
    | { type: 'resolveConflictUseServer' }
    | { type: 'resolveConflictKeepMine' };

export function initialComposerState(): ComposerState {
    return {
        postId: null,
        activeTab: BASE_TAB,
        saveState: 'idle',
        baselineUpdatedAt: null,
        baseText: '',
        destination: { kind: 'all' },
        autoSplitByAccount: {},
        overrideByAccount: {},
        mediaSubsetExcludes: new Set(),
        media: [],
        scheduleTray: { mode: 'now', pickedAt: null },
        conflict: null,
    };
}

function hydrate(post: PostView): ComposerState {
    const autoSplitByAccount: Record<string, boolean> = {};
    const overrideByAccount: Record<string, string | undefined> = {};
    const mediaSubsetExcludes = new Set<string>();

    for (const target of post.targets) {
        autoSplitByAccount[target.connected_account_id] = target.auto_split;
        const overrideText = target.content_override?.text;
        if (overrideText !== undefined && overrideText !== null) {
            overrideByAccount[target.connected_account_id] = overrideText;
        }
    }

    return {
        postId: post.id,
        activeTab: post.targets[0]?.connected_account_id ?? BASE_TAB,
        saveState: 'saved',
        baselineUpdatedAt: post.updated_at,
        baseText: post.base_text,
        destination:
            post.destination.kind === 'set' && post.destination.id
                ? { kind: 'set', id: post.destination.id }
                : post.destination.kind === 'account' && post.destination.id
                  ? { kind: 'account', id: post.destination.id }
                  : { kind: 'all' },
        autoSplitByAccount,
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

        case 'setPostId':
            return {
                ...state,
                postId: action.postId,
                baselineUpdatedAt: action.updatedAt,
                saveState: 'saved',
            };

        case 'updateBaseText':
            if (state.saveState === 'conflict') {
                return state;
            }

            return { ...state, baseText: action.text, saveState: 'dirty' };

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

        case 'setOverrideText':
            return {
                ...state,
                overrideByAccount: {
                    ...state.overrideByAccount,
                    [action.accountId]: action.text,
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
    content_override: { text: string; media_ids: string[] } | null;
};

export type PutBody = {
    base_text: string;
    destination: Destination;
    targets: PutTarget[];
    media_ids: string[];
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
                      text: override,
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
            content_override,
        };
    });

    return {
        base_text: state.baseText,
        destination: state.destination,
        targets,
        media_ids: state.media.map((m) => m.id),
        expected_updated_at: state.baselineUpdatedAt,
    };
}

/**
 * Derive a draft title from base text: the first non-empty line, trimmed, and
 * truncated to 80 characters with an ellipsis. Returns '' when the text has no
 * non-empty line.
 */
export function firstLineTitle(text: string): string {
    const trimmed = (
        text.split('\n').find((line) => line.trim().length > 0) ?? ''
    ).trim();

    return trimmed.length > 80 ? `${trimmed.slice(0, 80)}…` : trimmed;
}

import { segmentRefs } from '@/lib/compose/tiptap-doc';
import {
    type Account,
    BASE_TAB,
    type Destination,
    type GoogleBusinessProfileLocalPostOptions,
    type MediaView,
    type MentionPlaceholder,
    type Placement,
    type PostFormat,
    type PostView,
} from '@/types/compose';

/** Placement key used for media added before any explicit segment break exists. */
const HEAD_SEGMENT_REF = '__head__';

/**
 * The ordered segmentRefs for a post given its segment-break ids: `__head__`
 * (the first segment, which has no opening break) followed by each break id
 * in order. Thin re-export of `segmentRefs` from `tiptap-doc.ts` so
 * placement-consuming code doesn't need a second import for the same concept.
 */
export function segmentRefsFromBreaks(breaks: string[]): string[] {
    return segmentRefs(breaks);
}

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
    providerOptionsByAccount: Record<
        string,
        GoogleBusinessProfileLocalPostOptions | undefined
    >;
    overrideByAccount: Record<string, string[] | undefined>;
    media: MediaView[];
    /** Ordered ids of tiptap segment breaks; segment refs are derived from these. */
    segmentBreaks: string[];
    /** Canonical segmentRef -> ordered media ids. */
    placements: Record<string, string[]>;
    /** Per-account placement overrides; only present for accounts that diverge from canonical. */
    placementsByAccount: Record<string, Record<string, string[]>>;
    scheduleTray: ScheduleTray;
    conflict: PostView | null;
    autoRepost: boolean | null;
};

export type ComposerAction =
    | { type: 'hydrate'; post: PostView }
    | { type: 'syncServerPost'; post: PostView }
    | { type: 'setPostId'; postId: string; updatedAt: string }
    | { type: 'updateSegments'; segments: string[] }
    | { type: 'setMentions'; mentions: MentionPlaceholder[] }
    | { type: 'setActiveTab'; tab: string }
    | { type: 'setDestination'; destination: Destination }
    | { type: 'setAutoRepost'; value: boolean | null }
    | { type: 'toggleAutoSplit'; accountId: string }
    | { type: 'setFormat'; accountId: string; format: PostFormat }
    | {
          type: 'setGoogleBusinessProfileOptions';
          accountId: string;
          options: GoogleBusinessProfileLocalPostOptions;
      }
    | { type: 'disableAutoSplit'; accountIds: string[] }
    | { type: 'setOverrideSegments'; accountId: string; segments: string[] }
    | { type: 'discardOverride'; accountId: string }
    | { type: 'addMedia'; media: MediaView; segmentRef?: string }
    | { type: 'replaceMedia'; media: MediaView }
    | { type: 'removeMedia'; mediaId: string }
    | { type: 'reorderMedia'; ids: string[] }
    | {
          type: 'moveMediaToSegment';
          mediaId: string;
          segmentRef: string;
          accountId?: string;
      }
    | { type: 'removeMediaFromSegments'; mediaId: string; accountId?: string }
    | {
          type: 'reorderSegmentMedia';
          segmentRef: string;
          ids: string[];
          accountId?: string;
      }
    | { type: 'setSegmentBreaks'; breakIds: string[] }
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
        providerOptionsByAccount: {},
        overrideByAccount: {},
        media: [],
        segmentBreaks: [],
        placements: {},
        placementsByAccount: {},
        scheduleTray: scheduleAt
            ? { mode: 'pick', pickedAt: scheduleAt }
            : { mode: 'now', pickedAt: null },
        conflict: null,
        autoRepost: null,
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

/**
 * Group a flat `Placement[]` (as received over the wire) into a canonical
 * `segmentRef -> ordered media ids` map, ordering each segment's ids by
 * `position`.
 */
function groupPlacements(
    placements: Placement[] | undefined,
): Record<string, string[]> {
    const bySegment = new Map<string, Placement[]>();
    for (const placement of placements ?? []) {
        const list = bySegment.get(placement.segment_ref) ?? [];
        list.push(placement);
        bySegment.set(placement.segment_ref, list);
    }

    const grouped: Record<string, string[]> = {};
    for (const [segmentRef, list] of bySegment) {
        grouped[segmentRef] = [...list]
            .sort((a, b) => a.position - b.position)
            .map((p) => p.media_id);
    }

    return grouped;
}

/**
 * Structural equality for two `segmentRef -> ordered media ids` maps: same
 * set of segmentRefs, each with the same media ids in the same order. Used to
 * decide whether a target's placements actually diverge from canonical (a
 * non-divergent target must get NO `placementsByAccount` entry so it keeps
 * inheriting canonical edits).
 */
function placementMapsEqual(
    a: Record<string, string[]>,
    b: Record<string, string[]>,
): boolean {
    const aKeys = Object.keys(a).filter((key) => a[key].length > 0);
    const bKeys = Object.keys(b).filter((key) => b[key].length > 0);
    if (aKeys.length !== bKeys.length) {
        return false;
    }

    return aKeys.every(
        (key) =>
            b[key] !== undefined &&
            a[key].length === b[key].length &&
            a[key].every((id, i) => id === b[key][i]),
    );
}

/**
 * Write a per-account divergence-creating edit's resulting map back into
 * `placementsByAccount`. Mirrors `hydrate`'s divergence check: when the
 * account's new map is structurally equal to canonical, the entry must be
 * deleted entirely rather than written as an equal-but-present copy — an
 * existing entry, even one that happens to equal canonical right now, is
 * read by `buildPutBody` (and the composer's active-scope lookup) as "this
 * account has diverged", which permanently freezes it out of subsequent
 * canonical-scope edits until a full re-hydrate re-runs this same check.
 */
function withAccountPlacements(
    placementsByAccount: Record<string, Record<string, string[]>>,
    accountId: string,
    nextScope: Record<string, string[]>,
    canonicalPlacements: Record<string, string[]>,
): Record<string, Record<string, string[]>> {
    if (placementMapsEqual(nextScope, canonicalPlacements)) {
        const next = { ...placementsByAccount };
        delete next[accountId];

        return next;
    }

    return { ...placementsByAccount, [accountId]: nextScope };
}

/** Remove a media id from every segment array in a segmentRef -> ids map. */
function removeIdFromAllSegments(
    map: Record<string, string[]>,
    mediaId: string,
): Record<string, string[]> {
    return Object.fromEntries(
        Object.entries(map).map(([segmentRef, ids]) => [
            segmentRef,
            ids.filter((id) => id !== mediaId),
        ]),
    );
}

/**
 * Re-home a segmentRef -> ids map after the thread structure changes (a
 * section break was inserted, deleted, or reordered). A ref that no longer
 * exists (its break was deleted — segments merge into the previous one) has
 * its media moved onto the nearest still-surviving EARLIER ref in the old
 * order, falling back to `__head__` (which always survives) — so deleting a
 * thread break folds that segment's media into the segment it merged into,
 * instead of orphaning it. A no-op (same reference) when nothing was removed.
 */
function migratePlacementsAfterBreakChange(
    map: Record<string, string[]>,
    oldRefs: string[],
    newRefs: string[],
): Record<string, string[]> {
    const survives = new Set(newRefs);
    if (oldRefs.every((ref) => survives.has(ref))) {
        return map;
    }

    function targetFor(ref: string): string {
        if (survives.has(ref)) {
            return ref;
        }
        const idx = oldRefs.indexOf(ref);
        for (let i = idx - 1; i >= 0; i--) {
            if (survives.has(oldRefs[i])) {
                return oldRefs[i];
            }
        }

        return HEAD_SEGMENT_REF;
    }

    const next: Record<string, string[]> = {};
    for (const [ref, ids] of Object.entries(map)) {
        if (ids.length === 0) {
            continue;
        }
        const target = targetFor(ref);
        next[target] = [...(next[target] ?? []), ...ids];
    }

    return next;
}

/**
 * Fold any media that has no placement (a legacy draft whose media predates
 * per-segment placements, or media attached before its placement persisted)
 * onto the first segment, so it renders in the composer instead of vanishing —
 * mirroring the server resolver's "no placements → all media on post 1"
 * fallback. Returns the map unchanged when nothing is orphaned.
 */
function withOrphanMediaOnHead(
    map: Record<string, string[]>,
    media: MediaView[],
): Record<string, string[]> {
    const placed = new Set(Object.values(map).flat());
    const orphans = media.map((m) => m.id).filter((id) => !placed.has(id));
    if (orphans.length === 0) {
        return map;
    }

    return { ...map, __head__: [...(map.__head__ ?? []), ...orphans] };
}

function hydrate(post: PostView): ComposerState {
    const autoSplitByAccount: Record<string, boolean> = {};
    const formatByAccount: Record<string, PostFormat> = {};
    const providerOptionsByAccount: Record<
        string,
        GoogleBusinessProfileLocalPostOptions | undefined
    > = {};
    const overrideByAccount: Record<string, string[] | undefined> = {};
    const placementsByAccount: Record<string, Record<string, string[]>> = {};
    // Raw canonical grouping drives the per-account divergence check below;
    // the display map additionally folds in any unplaced media.
    const canonicalPlacements = groupPlacements(post.placements);
    const displayPlacements = withOrphanMediaOnHead(
        canonicalPlacements,
        post.media,
    );

    for (const target of post.targets) {
        autoSplitByAccount[target.connected_account_id] = target.auto_split;
        formatByAccount[target.connected_account_id] = target.format;
        const googleBusinessProfileOptions =
            target.provider_options?.google_business_profile;
        if (googleBusinessProfileOptions !== undefined) {
            providerOptionsByAccount[target.connected_account_id] =
                googleBusinessProfileOptions;
        }
        const overrideSegments = target.content_override?.segments;
        if (overrideSegments !== undefined && overrideSegments !== null) {
            overrideByAccount[target.connected_account_id] = overrideSegments;
        }
        // Only seed a placementsByAccount entry when the target's placements
        // actually diverge from canonical. A non-divergent entry would freeze
        // that account onto a stale snapshot: buildPutBody treats ANY entry
        // here as "diverged" and stops emitting the account's per-target
        // segment_breaks/placements from canonical, so a later canonical-scope
        // edit (no accountId) would silently never reach that account.
        if (target.placements !== undefined) {
            const grouped = groupPlacements(target.placements);
            if (!placementMapsEqual(grouped, canonicalPlacements)) {
                placementsByAccount[target.connected_account_id] = grouped;
            }
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
                    : post.destination.kind === 'none'
                      ? { kind: 'none' }
                      : { kind: 'all' },
        autoSplitByAccount,
        formatByAccount,
        providerOptionsByAccount,
        overrideByAccount,
        media: post.media,
        segmentBreaks: post.segment_breaks ?? [],
        placements: displayPlacements,
        placementsByAccount,
        scheduleTray: {
            mode: post.scheduled_at ? 'pick' : 'now',
            pickedAt: post.scheduled_at ?? null,
        },
        conflict: null,
        autoRepost: post.auto_repost ?? null,
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

        case 'setAutoRepost':
            return {
                ...state,
                autoRepost: action.value,
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

        case 'setGoogleBusinessProfileOptions':
            return {
                ...state,
                providerOptionsByAccount: {
                    ...state.providerOptionsByAccount,
                    [action.accountId]: action.options,
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

        case 'addMedia': {
            const segmentRef = action.segmentRef ?? HEAD_SEGMENT_REF;
            const existing = state.placements[segmentRef] ?? [];

            return {
                ...state,
                media: [...state.media, action.media],
                placements: {
                    ...state.placements,
                    [segmentRef]: [...existing, action.media.id],
                },
                // A newly-added item must also land in every account that has
                // already diverged from canonical (has a placementsByAccount
                // entry) — otherwise it silently vanishes from that account's
                // preview and publish payload once a divergence exists. Never
                // create a new entry for a non-diverged account; that would
                // invent a divergence where none existed and freeze it off
                // future canonical-scope edits (see buildPutBody).
                placementsByAccount: Object.fromEntries(
                    Object.entries(state.placementsByAccount).map(
                        ([accountId, map]) => [
                            accountId,
                            {
                                ...map,
                                [segmentRef]: [
                                    ...(map[segmentRef] ?? []),
                                    action.media.id,
                                ],
                            },
                        ],
                    ),
                ),
                saveState: 'dirty',
            };
        }

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
                placements: removeIdFromAllSegments(
                    state.placements,
                    action.mediaId,
                ),
                placementsByAccount: Object.fromEntries(
                    Object.entries(state.placementsByAccount).map(
                        ([accountId, map]) => [
                            accountId,
                            removeIdFromAllSegments(map, action.mediaId),
                        ],
                    ),
                ),
                saveState: 'dirty',
            };

        case 'moveMediaToSegment': {
            const scopeKey = action.accountId;
            const currentScope: Record<string, string[]> = scopeKey
                ? (state.placementsByAccount[scopeKey] ?? state.placements)
                : state.placements;
            const withoutId = removeIdFromAllSegments(
                currentScope,
                action.mediaId,
            );
            const nextScope: Record<string, string[]> = {
                ...withoutId,
                [action.segmentRef]: [
                    ...(withoutId[action.segmentRef] ?? []),
                    action.mediaId,
                ],
            };

            if (scopeKey) {
                return {
                    ...state,
                    placementsByAccount: withAccountPlacements(
                        state.placementsByAccount,
                        scopeKey,
                        nextScope,
                        state.placements,
                    ),
                    saveState: 'dirty',
                };
            }

            return { ...state, placements: nextScope, saveState: 'dirty' };
        }

        case 'removeMediaFromSegments': {
            const scopeKey = action.accountId;
            if (scopeKey) {
                const currentScope =
                    state.placementsByAccount[scopeKey] ?? state.placements;
                const nextScope = removeIdFromAllSegments(
                    currentScope,
                    action.mediaId,
                );

                return {
                    ...state,
                    placementsByAccount: withAccountPlacements(
                        state.placementsByAccount,
                        scopeKey,
                        nextScope,
                        state.placements,
                    ),
                    saveState: 'dirty',
                };
            }

            return {
                ...state,
                media: state.media.filter((m) => m.id !== action.mediaId),
                placements: removeIdFromAllSegments(
                    state.placements,
                    action.mediaId,
                ),
                placementsByAccount: Object.fromEntries(
                    Object.entries(state.placementsByAccount).map(
                        ([accountId, map]) => [
                            accountId,
                            removeIdFromAllSegments(map, action.mediaId),
                        ],
                    ),
                ),
                saveState: 'dirty',
            };
        }

        case 'reorderSegmentMedia': {
            const scopeKey = action.accountId;
            if (scopeKey) {
                const currentScope =
                    state.placementsByAccount[scopeKey] ?? state.placements;
                const nextScope = {
                    ...currentScope,
                    [action.segmentRef]: action.ids,
                };

                return {
                    ...state,
                    placementsByAccount: withAccountPlacements(
                        state.placementsByAccount,
                        scopeKey,
                        nextScope,
                        state.placements,
                    ),
                    saveState: 'dirty',
                };
            }

            return {
                ...state,
                placements: {
                    ...state.placements,
                    [action.segmentRef]: action.ids,
                },
                saveState: 'dirty',
            };
        }

        case 'setSegmentBreaks': {
            // A deleted break merges two segments — re-home any media that was
            // placed on the now-gone ref onto the segment it merged into,
            // rather than leaving it orphaned (still in the media pool, but
            // owned by no surviving segment).
            const oldRefs = segmentRefsFromBreaks(state.segmentBreaks);
            const newRefs = segmentRefsFromBreaks(action.breakIds);

            return {
                ...state,
                segmentBreaks: action.breakIds,
                placements: migratePlacementsAfterBreakChange(
                    state.placements,
                    oldRefs,
                    newRefs,
                ),
                placementsByAccount: Object.fromEntries(
                    Object.entries(state.placementsByAccount).map(
                        ([accountId, map]) => [
                            accountId,
                            migratePlacementsAfterBreakChange(
                                map,
                                oldRefs,
                                newRefs,
                            ),
                        ],
                    ),
                ),
                saveState: 'dirty',
            };
        }

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
    provider_options?: {
        google_business_profile: GoogleBusinessProfileLocalPostOptions;
    };
    segment_breaks?: string[];
    placements?: Placement[];
};

export type PutBody = {
    segments: string[];
    destination: Destination;
    targets: PutTarget[];
    media_ids: string[];
    mentions: MentionPlaceholder[];
    expected_updated_at: string | null;
    auto_repost: boolean | null;
    segment_breaks: string[];
    placements: Placement[];
};

/**
 * Flatten a canonical/per-account `segmentRef -> ordered media ids` map into
 * the wire `Placement[]` shape, deriving `position` from each segment's array
 * index.
 */
export function flattenPlacements(map: Record<string, string[]>): Placement[] {
    const flat: Placement[] = [];
    for (const [segmentRef, ids] of Object.entries(map)) {
        ids.forEach((mediaId, position) => {
            flat.push({ media_id: mediaId, segment_ref: segmentRef, position });
        });
    }

    return flat;
}

/**
 * Build the autosave PUT payload. Each target ALWAYS carries an explicit
 * content_override: the override shape when the account has a local override,
 * or `null` to explicitly clear any stored override server-side (a discard must
 * survive reload; omitting the key would let the old override silently persist).
 *
 * `segment_breaks`/`placements` are emitted at the top level (canonical) and,
 * per target, only when that account has a diverged placements map — omitting
 * them for non-diverged accounts tells the server to keep inheriting canonical.
 */
export function buildPutBody(
    state: ComposerState,
    accountIds: string[],
): PutBody {
    const targets: PutTarget[] = accountIds.map((accountId) => {
        const override = state.overrideByAccount[accountId];
        // Per-account media now lives in per-target placements (see below), not
        // in content_override.media_ids — the old per-account exclude mechanism
        // is retired. media_ids here is the full set purely to satisfy the
        // stored override shape; publishing reads placements, not this field.
        const content_override =
            override !== undefined
                ? {
                      segments: override,
                      media_ids: state.media.map((m) => m.id),
                  }
                : null;

        const divergedPlacements = state.placementsByAccount[accountId];
        const providerOptions = state.providerOptionsByAccount[accountId];

        return {
            connected_account_id: accountId,
            auto_split: state.autoSplitByAccount[accountId] ?? true,
            format: state.formatByAccount[accountId] ?? 'feed',
            content_override,
            ...(providerOptions !== undefined
                ? {
                      provider_options: {
                          google_business_profile: providerOptions,
                      },
                  }
                : {}),
            ...(divergedPlacements !== undefined
                ? {
                      segment_breaks: state.segmentBreaks,
                      placements: flattenPlacements(divergedPlacements),
                  }
                : {}),
        };
    });

    return {
        segments: state.segments,
        destination: state.destination,
        targets,
        media_ids: state.media.map((m) => m.id),
        mentions: state.mentions,
        expected_updated_at: state.baselineUpdatedAt,
        auto_repost: state.autoRepost,
        segment_breaks: state.segmentBreaks,
        placements: flattenPlacements(state.placements),
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

    if (
        JSON.stringify(state.segmentBreaks) !==
        JSON.stringify(post.segment_breaks ?? [])
    ) {
        return false;
    }

    // Compare through the same "fold unplaced media onto __head__" display
    // semantics `hydrate` applies, not the raw server grouping — a legacy post
    // with media but no placement rows groups to `{}` while the hydrated state
    // folds that same media onto `__head__`; those are the same post and must
    // not be flagged as diverged.
    if (
        JSON.stringify(
            normalizePlacements(
                withOrphanMediaOnHead(state.placements, state.media),
            ),
        ) !==
        JSON.stringify(
            normalizePlacements(
                withOrphanMediaOnHead(
                    groupPlacements(post.placements),
                    post.media,
                ),
            ),
        )
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
 * Drop empty segment arrays from a placements map so a segment that was
 * emptied out (e.g. its last media id moved elsewhere) compares equal to one
 * that never had a key for that segment at all.
 */
function normalizePlacements(
    map: Record<string, string[]>,
): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    for (const [segmentRef, ids] of Object.entries(map)) {
        if (ids.length > 0) {
            out[segmentRef] = ids;
        }
    }

    return out;
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
 * More than one thread post exists. With a single thread, the global
 * toolbar's "add media" button already attaches to it — the per-segment
 * affordance only earns its keep once there's more than one segment to
 * choose between.
 */
export function hasMultipleThreads(state: ComposerState): boolean {
    return state.segmentBreaks.length > 0;
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

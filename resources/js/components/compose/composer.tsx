import { Link, useHttp } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import { useEffect, useReducer, useRef, useState } from 'react';

import WorkspaceMentionController from '@/actions/App/Http/Controllers/WorkspaceMentionController';
import { useAutosave } from '@/hooks/compose/use-autosave';
import { useMediaUploads } from '@/hooks/compose/use-media-uploads';
import { useNextSlot } from '@/hooks/compose/use-next-slot';
import { usePublishStatus } from '@/hooks/compose/use-publish-status';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import {
    composerReducer,
    initialComposerState,
    pickActiveAccount,
    shouldShowConnectAccountPrompt,
    type ComposerState,
} from '@/lib/compose/composer-state';
import {
    replaceMentionTokens,
    syncMentionsFromText,
} from '@/lib/compose/mentions';
import { postCapabilities } from '@/lib/posts/capabilities';
import { index as accountsRoute } from '@/routes/accounts';
import {
    BASE_TAB,
    type Account,
    type AccountSet,
    type Destination,
    type MentionPlaceholder,
    type PlatformLimits,
    type PlatformName,
    type PostView,
    type WorkspaceMention,
} from '@/types/compose';

import CharCounter from './char-counter';
import { ComposerToolbar } from './composer-toolbar';
import { ConflictDialog } from './conflict-dialog';
import DestinationSelector from './destination-selector';
import EditorBody from './editor-body';
import PlatformTabs from './platform-tabs';
import SaveIndicator from './save-indicator';
import { ScheduleTray } from './schedule-tray';
import { SubmitBar } from './submit-bar';
import { TargetStatusChips } from './target-status-chips';

type ComposerProps = {
    post: PostView | null;
    accounts: Account[];
    sets: AccountSet[];
    limits: PlatformLimits[];
    /** ISO time to pre-arm the schedule tray with (e.g. from a calendar slot click). */
    initialScheduleAt?: string | null;
    /** Seed the destination for a brand-new post (e.g. compose-for-channel). */
    initialDestination?: Destination | null;
    /** Focus the editor as soon as it mounts. */
    autoFocusEditor?: boolean;
    initialSavedMentions?: WorkspaceMention[];
};

const EMPTY_SAVED_MENTIONS: WorkspaceMention[] = [];

function accountIdsFor(
    state: ComposerState,
    accounts: Account[],
    sets: AccountSet[],
): string[] {
    const { destination } = state;
    if (destination.kind === 'account') {
        return accounts.filter((a) => a.id === destination.id).map((a) => a.id);
    }
    if (destination.kind === 'set') {
        const set = sets.find((s) => s.id === destination.id);

        return set ? set.connected_account_ids : [];
    }
    if (destination.kind === 'accounts') {
        const selected = new Set(destination.ids);

        return accounts.filter((a) => selected.has(a.id)).map((a) => a.id);
    }

    return accounts.map((a) => a.id);
}

function measure(text: string, platform: PlatformName): number {
    // oxlint-disable-next-line no-misused-spread -- intentional code-point count
    return platform === 'x' ? text.length : [...text].length;
}

export default function Composer({
    post,
    accounts,
    sets,
    limits,
    initialScheduleAt = null,
    initialDestination = null,
    autoFocusEditor = false,
    initialSavedMentions = EMPTY_SAVED_MENTIONS,
}: ComposerProps) {
    const schedulingTz = useSchedulingTimezone();
    const saveMentionHttp = useHttp<
        Record<string, never>,
        { mention: WorkspaceMention }
    >({});
    const [savedMentions, setSavedMentions] = useState(initialSavedMentions);
    useEffect(() => {
        setSavedMentions(initialSavedMentions);
    }, [initialSavedMentions]);
    const [state, dispatch] = useReducer(composerReducer, post, (p) =>
        p
            ? composerReducer(initialComposerState(), {
                  type: 'hydrate',
                  post: p,
              })
            : initialComposerState(initialScheduleAt, initialDestination),
    );

    // Inertia reuses this component across same-page visits (no remount), so
    // the reducer's mount-time hydrate is the only seed. When a navigation or
    // reload delivers a newer/different server `post` — e.g. after a schedule,
    // queue, or publish that mutates `updated_at` outside the autosave path —
    // re-sync so the optimistic-concurrency baseline tracks the server.
    // Autosave uses standalone XHR that never changes this prop, so this never
    // fires for in-flight draft edits.
    const syncedSig = useRef(post ? `${post.id}@${post.updated_at}` : null);
    useEffect(() => {
        if (!post) {
            return;
        }
        const sig = `${post.id}@${post.updated_at}`;
        if (sig === syncedSig.current) {
            return;
        }
        syncedSig.current = sig;
        dispatch({ type: 'syncServerPost', post });
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [post?.id, post?.updated_at]);

    const queueState = useNextSlot(
        state.scheduleTray.mode === 'queue',
        schedulingTz,
    );

    const destinationAccountIds = accountIdsFor(state, accounts, sets);
    const tabAccounts = accounts.filter((a) =>
        destinationAccountIds.includes(a.id),
    );
    const selectedVideoLimits = limits.filter((l) =>
        tabAccounts.some((a) => a.platform === l.platform),
    );
    const { flush, ensurePost } = useAutosave({
        state,
        accountIds: destinationAccountIds,
        dispatch,
    });
    const publishStatus = usePublishStatus({ pagePost: post });

    // Owns the media-upload pipeline (image/video validation + upload). Lifted
    // here so both the editor (⌘/Ctrl+V paste) and the toolbar (picker/drop)
    // feed the same handleFiles and share one in-flight `pending` list.
    const mediaUploads = useMediaUploads({
        media: state.media,
        videoLimits: selectedVideoLimits,
        onEnsurePost: ensurePost,
        onAddMedia: (m) => dispatch({ type: 'addMedia', media: m }),
    });

    // Persist a destination change immediately rather than waiting out the
    // autosave debounce. This MUST run in an effect — AFTER the reducer commits
    // — so `flush` closes over the new destination. Calling flush() synchronously
    // inside the selector's onChange captured the PRE-dispatch state and
    // persisted the OLD destination, so a quick switch-then-publish published to
    // the previous set (e.g. the default "all accounts"). flush's own guards make
    // this a no-op on mount and on server-driven hydrates (saveState is 'saved'),
    // so it only fires for genuine user switches.
    const flushedDestination = useRef(state.destination);
    useEffect(() => {
        if (flushedDestination.current === state.destination) {
            return;
        }
        flushedDestination.current = state.destination;
        void flush();
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [state.destination]);

    // A post that isn't a draft is read-only: show its content/media + live
    // status, but no editing, media changes, or re-publishing.
    const readOnly = post !== null && !postCapabilities(post).canEdit;

    const activeAccount = pickActiveAccount(tabAccounts, state.activeTab);
    const showConnectAccountPrompt = shouldShowConnectAccountPrompt(
        accounts,
        activeAccount,
    );
    const activeText =
        activeAccount && state.overrideByAccount[activeAccount.id] !== undefined
            ? (state.overrideByAccount[activeAccount.id] as string)
            : state.baseText;

    function limitForPlatform(platform: PlatformName): number {
        return limits.find((l) => l.platform === platform)?.maxLength ?? 0;
    }

    function limitForAccount(account: Account): number {
        return account.max_text_length || limitForPlatform(account.platform);
    }

    function severityFor(accountId: string): 'ok' | 'warn' | 'over' {
        const account = tabAccounts.find((a) => a.id === accountId);
        if (!account) {
            return 'ok';
        }
        const text =
            state.overrideByAccount[accountId] !== undefined
                ? (state.overrideByAccount[accountId] as string)
                : state.baseText;
        const resolvedText = replaceMentionTokens(
            text,
            state.mentions,
            account.platform,
        );
        const limit = limitForAccount(account);
        const count = measure(resolvedText, account.platform);
        if (limit > 0 && count > limit) {
            return 'over';
        }

        return limit > 0 && count >= limit * 0.9 ? 'warn' : 'ok';
    }

    function chipFor(accountId: string): string {
        const account = tabAccounts.find((a) => a.id === accountId);
        if (!account) {
            return '';
        }
        const target = post?.targets.find(
            (t) => t.connected_account_id === accountId,
        );
        return String(target?.sections.length ?? 1);
    }

    function syncMentions(
        nextBaseText: string,
        nextOverrides = state.overrideByAccount,
    ) {
        const mentionSource = [nextBaseText, ...Object.values(nextOverrides)]
            .filter((value): value is string => value !== undefined)
            .join('\n');
        const mentions = syncMentionsFromText(
            mentionSource,
            state.mentions,
            savedMentions,
        );
        if (JSON.stringify(mentions) !== JSON.stringify(state.mentions)) {
            dispatch({ type: 'setMentions', mentions });
        }
    }

    function renameMention(
        mention: MentionPlaceholder,
        next: MentionPlaceholder,
    ) {
        const replace = (text: string): string =>
            text.split(mention.label).join(next.label);
        const overrideByAccount = Object.fromEntries(
            Object.entries(state.overrideByAccount).map(([accountId, text]) => [
                accountId,
                text === undefined ? undefined : replace(text),
            ]),
        ) as Record<string, string | undefined>;

        dispatch({ type: 'updateBaseText', text: replace(state.baseText) });
        for (const [accountId, text] of Object.entries(overrideByAccount)) {
            if (text !== undefined) {
                dispatch({ type: 'setOverrideText', accountId, text });
            }
        }
        dispatch({
            type: 'setMentions',
            mentions: state.mentions.map((item) =>
                item.id === mention.id ? next : item,
            ),
        });
    }

    function applySavedMention(
        mention: MentionPlaceholder,
        saved: WorkspaceMention,
    ) {
        renameMention(mention, {
            id: saved.name
                .replace(/^@/, '')
                .toLowerCase()
                .replace(/[^a-z0-9_-]+/g, '-'),
            label: saved.name,
            handles: saved.handles,
        });
    }

    async function saveMention(mention: MentionPlaceholder): Promise<void> {
        saveMentionHttp.transform(() => ({
            name: mention.label,
            handles: mention.handles,
        }));
        const response = await saveMentionHttp.post(
            WorkspaceMentionController.store().url,
        );

        setSavedMentions((current) => {
            const others = current.filter(
                (item) =>
                    item.id !== response.mention.id &&
                    item.name !== response.mention.name,
            );

            return [...others, response.mention].sort((left, right) =>
                left.name.localeCompare(right.name),
            );
        });
    }

    function handleText(text: string) {
        if (
            activeAccount &&
            state.overrideByAccount[activeAccount.id] !== undefined
        ) {
            const overrideByAccount = {
                ...state.overrideByAccount,
                [activeAccount.id]: text,
            };
            dispatch({
                type: 'setOverrideText',
                accountId: activeAccount.id,
                text,
            });
            syncMentions(state.baseText, overrideByAccount);

            return;
        }
        dispatch({ type: 'updateBaseText', text });
        syncMentions(text);
    }

    const activeTarget = activeAccount
        ? post?.targets.find((t) => t.connected_account_id === activeAccount.id)
        : undefined;
    const activeSectionTotal = activeTarget?.sections.length ?? 1;
    const overrideActive =
        activeAccount !== null &&
        state.overrideByAccount[activeAccount.id] !== undefined;
    const mentionPlatforms = Array.from(
        new Set(tabAccounts.map((account) => account.platform)),
    );

    return (
        <div className="overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm transition-[box-shadow,border-color] duration-300 focus-within:border-primary/25 focus-within:shadow-[0_0_16px_-6px_color-mix(in_oklch,var(--primary)_28%,transparent)]">
            {/* Tab-strip row */}
            <div className="flex items-center border-b border-border px-2 pt-2">
                <PlatformTabs
                    accounts={tabAccounts}
                    activeTab={activeAccount?.id ?? state.activeTab}
                    onChange={(tab) => dispatch({ type: 'setActiveTab', tab })}
                    chipFor={chipFor}
                    stateFor={severityFor}
                    hasOverride={(accountId) =>
                        state.overrideByAccount[accountId] !== undefined
                    }
                />
                <div className="ml-auto flex items-center gap-2 pr-1">
                    <DestinationSelector
                        accounts={accounts}
                        sets={sets}
                        destination={state.destination}
                        disabled={readOnly}
                        onChange={(destination) =>
                            dispatch({ type: 'setDestination', destination })
                        }
                    />
                    {!readOnly && (
                        <SaveIndicator
                            state={state.saveState}
                            lastSavedAt={
                                state.baselineUpdatedAt
                                    ? Date.parse(state.baselineUpdatedAt)
                                    : null
                            }
                        />
                    )}
                </div>
            </div>

            {/* Override banner (inside EditorBody) + editor */}
            <EditorBody
                value={activeText}
                onChange={handleText}
                onBlur={flush}
                editable={!readOnly}
                autoFocus={autoFocusEditor}
                onPasteFiles={readOnly ? undefined : mediaUploads.handleFiles}
                overrideBanner={overrideActive}
                activePlatformLabel={activeAccount?.platform ?? null}
                onResetOverride={() =>
                    activeAccount &&
                    dispatch({
                        type: 'discardOverride',
                        accountId: activeAccount.id,
                    })
                }
                mentions={state.mentions}
                mentionPlatforms={mentionPlatforms}
                savedMentions={savedMentions}
                onMentionNameChange={renameMention}
                onApplySavedMention={applySavedMention}
                onSaveMention={saveMention}
                saveMentionProcessing={saveMentionHttp.processing}
                onMentionsChange={(mentions) =>
                    dispatch({ type: 'setMentions', mentions })
                }
                markerState={
                    activeAccount
                        ? {
                              platform: activeAccount.platform,
                              autoSplit:
                                  state.autoSplitByAccount[activeAccount.id] ??
                                  true,
                              limit: limitForAccount(activeAccount),
                              threadMax:
                                  limits.find(
                                      (l) =>
                                          l.platform === activeAccount.platform,
                                  )?.threadMax ?? null,
                          }
                        : undefined
                }
            />

            {/* Counter row — or the connect prompt when there are no accounts. */}
            {activeAccount ? (
                <CharCounter
                    count={measure(
                        replaceMentionTokens(
                            activeText,
                            state.mentions,
                            activeAccount.platform,
                        ),
                        activeAccount.platform,
                    )}
                    limit={limitForAccount(activeAccount)}
                    sectionTotal={activeSectionTotal}
                    state={severityFor(activeAccount.id)}
                />
            ) : showConnectAccountPrompt ? (
                <div className="px-4 pb-3.5 sm:px-[26px]">
                    <Link
                        href={accountsRoute().url}
                        className="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-2.5 py-1 text-[12px] tracking-[-0.005em] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
                    >
                        <Plug className="size-3.5" aria-hidden />
                        Connect an account to publish
                    </Link>
                </div>
            ) : null}

            {/* Toolbar — editing controls when editable; just the attached
                media when read-only (skipped entirely if there's none). */}
            {(!readOnly || state.media.length > 0) && (
                <ComposerToolbar
                    readOnly={readOnly}
                    activePlatform={activeAccount?.platform}
                    autoSplit={
                        activeAccount
                            ? (state.autoSplitByAccount[activeAccount.id] ??
                              true)
                            : false
                    }
                    overrideActive={overrideActive}
                    showSplitControls={activeAccount !== null}
                    media={state.media}
                    onRemove={(id) =>
                        dispatch({ type: 'removeMedia', mediaId: id })
                    }
                    onReorder={(ids) => dispatch({ type: 'reorderMedia', ids })}
                    onToggleAutoSplit={() =>
                        activeAccount &&
                        dispatch({
                            type: 'toggleAutoSplit',
                            accountId: activeAccount.id,
                        })
                    }
                    onToggleOverride={() => {
                        if (!activeAccount) {
                            return;
                        }
                        if (
                            state.overrideByAccount[activeAccount.id] !==
                            undefined
                        ) {
                            dispatch({
                                type: 'discardOverride',
                                accountId: activeAccount.id,
                            });
                        } else {
                            dispatch({
                                type: 'setOverrideText',
                                accountId: activeAccount.id,
                                text: state.baseText,
                            });
                        }
                    }}
                    isExcluded={(mediaId) =>
                        activeAccount
                            ? state.mediaSubsetExcludes.has(
                                  `${mediaId}:${activeAccount.id}`,
                              )
                            : false
                    }
                    onToggleExclude={(mediaId) =>
                        activeAccount &&
                        dispatch({
                            type: 'toggleMediaExclude',
                            mediaId,
                            accountId: activeAccount.id,
                        })
                    }
                    pending={mediaUploads.pending}
                    handleFiles={mediaUploads.handleFiles}
                    dismissPending={mediaUploads.dismissPending}
                />
            )}

            {/* Schedule + submit row — hidden once the post is read-only. */}
            {!readOnly && (
                <div className="flex flex-col items-stretch gap-3 border-t border-border bg-muted/55 px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-x-3 sm:px-[14px]">
                    <ScheduleTray
                        tray={state.scheduleTray}
                        onChange={(tray) =>
                            dispatch({ type: 'setScheduleTray', tray })
                        }
                        tz={schedulingTz}
                        queueState={queueState}
                    />
                    <SubmitBar
                        tray={state.scheduleTray}
                        postId={state.postId}
                        disabled={accounts.length === 0}
                        queueDisabled={queueState.status !== 'found'}
                        uploading={mediaUploads.isUploading}
                        onSaveDraft={flush}
                        onEnsurePost={ensurePost}
                        onOptimisticSubmit={publishStatus.applyOptimistic}
                        onServerPost={publishStatus.applyServerPost}
                    />
                </div>
            )}

            {/* Live publish status — only once a publish/queue/schedule has run */}
            {publishStatus.snapshot &&
                publishStatus.snapshot.status !== 'draft' &&
                publishStatus.snapshot.targets.length > 0 && (
                    <div className="border-t border-border px-3 py-3 sm:px-[14px]">
                        <TargetStatusChips
                            targets={publishStatus.snapshot.targets}
                            retryingIds={publishStatus.retryingIds}
                            onRetry={(targetId) =>
                                void publishStatus.retry(targetId)
                            }
                        />
                    </div>
                )}

            {state.conflict !== null && (
                <ConflictDialog
                    open
                    myBaseText={state.baseText}
                    serverPost={state.conflict}
                    onKeepMine={() =>
                        dispatch({ type: 'resolveConflictKeepMine' })
                    }
                    onUseServer={() =>
                        dispatch({ type: 'resolveConflictUseServer' })
                    }
                />
            )}
        </div>
    );
}

export { BASE_TAB };
